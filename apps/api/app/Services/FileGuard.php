<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Guardia central de subida de archivos.
 *
 * Principio: NUNCA confiar en la extensión ni en el Content-Type que manda el
 * cliente. Un `virus.exe` renombrado a `libro.pdf` debe rechazarse SIEMPRE.
 *
 * Capas de defensa:
 *  1. Blocklist de extensiones peligrosas en CUALQUIER parte del nombre
 *     (atrapa dobles extensiones: `libro.php.pdf`, `a.exe.txt`).
 *  2. Allowlist de extensión declarada.
 *  3. MIME REAL leído del contenido (finfo) contra allowlist por tipo.
 *  4. Magic bytes (firma binaria) coherentes con el tipo declarado.
 *  5. Imágenes verificadas con getimagesize* (rechaza SVG y polyglots).
 *  6. Tamaño máximo por categoría.
 *  7. El nombre almacenado es SIEMPRE aleatorio + extensión canónica
 *     derivada del contenido validado (jamás el nombre del cliente).
 *
 * Lanza ValidationException (422) con mensaje claro si algo no cuadra.
 */
class FileGuard
{
    /** Obras: documentos de lectura. */
    public const WORK_EXTS = ['pdf', 'epub', 'docx', 'txt'];

    public const WORK_MIMES = [
        'application/pdf',
        'application/epub+zip',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        // EPUB y DOCX son contenedores ZIP: finfo suele reportarlos como zip.
        'application/zip',
        'application/octet-stream',
    ];

    /** Tope duro general (rechazo temprano antes de conocer el tipo). */
    public const WORK_MAX_BYTES = 100 * 1024 * 1024; // 100 MB

    /** Límite por extensión de obra (más fino que el tope general). */
    public const WORK_MAX_BYTES_BY_EXT = [
        'txt' => 5 * 1024 * 1024,    // 5 MB
        'docx' => 25 * 1024 * 1024,  // 25 MB
        'epub' => 50 * 1024 * 1024,  // 50 MB
        'pdf' => 100 * 1024 * 1024,  // 100 MB
    ];

    private const WORK_MAX_HUMAN_BY_EXT = [
        'txt' => '5 MB',
        'docx' => '25 MB',
        'epub' => '50 MB',
        'pdf' => '100 MB',
    ];

    /** Formatos que el IMPORTADOR puede convertir en capítulos (no PDF). */
    public const IMPORT_EXTS = ['txt', 'docx', 'epub'];

    /** Límites anti zip-bomb para contenedores ZIP (EPUB/DOCX). */
    public const ARCHIVE_MAX_UNCOMPRESSED = 300 * 1024 * 1024; // 300 MB descomprimido

    public const ARCHIVE_MAX_ENTRIES = 5000;

    /** Imágenes: portadas y avatares. */
    public const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    // ===================== Cómics (archivos de imágenes) =====================

    /** Archivos contenedores de cómic (ZIP de imágenes). CBZ = ZIP renombrado. */
    public const COMIC_ARCHIVE_EXTS = ['cbz', 'zip'];

    public const COMIC_ARCHIVE_MIMES = [
        'application/zip', 'application/octet-stream',
        'application/x-cbz', 'application/vnd.comicbook+zip', 'application/x-zip-compressed',
    ];

    /** Imágenes admitidas dentro de un cómic (página por página). */
    public const COMIC_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const COMIC_IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    public const COMIC_IMAGE_MAX_BYTES = 20 * 1024 * 1024; // 20 MB por imagen

    /** Topes anti-DoS para cómics (evitan "50 mil imágenes" o zip bombs). */
    public const MAX_COMIC_PAGES = 3000;       // nº máximo de imágenes/páginas
    public const MAX_IMAGE_DIM = 8000;         // 8000 × 8000 px por página
    public const COVER_MAX_DIM = 5000;         // 5000 × 5000 px portada
    public const COMIC_ARCHIVE_MAX_UNCOMPRESSED = 2147483648; // 2 GB descomprimido

    /**
     * Límite de tamaño de subida por nivel de creador / plan.
     *  - Normal: 250 MB · Verificado: 500 MB · Destacado o Premium Plus: 1 GB
     */
    public const CREATOR_MAX_BYTES = [
        'normal' => 250 * 1024 * 1024,
        'verified' => 500 * 1024 * 1024,
        'featured' => 1024 * 1024 * 1024,
    ];

    public function premiumWorkMaxBytes(User $user): int
    {
        if (Entitlements::isPremiumPlus($user) || $user->creator_level === 'featured') {
            return self::CREATOR_MAX_BYTES['featured'];
        }
        if ($user->creator_level === 'verified') {
            return self::CREATOR_MAX_BYTES['verified'];
        }

        return self::CREATOR_MAX_BYTES['normal'];
    }

    /**
     * Extensiones SIEMPRE bloqueadas (ejecutables, scripts, web peligrosa),
     * sin importar la categoría ni el renombrado.
     */
    public const BLOCKED_EXTS = [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'ps1', 'psm1', 'vbs', 'vbe',
        'js', 'jse', 'mjs', 'jar', 'dll', 'so', 'dylib', 'bin', 'sh', 'bash', 'zsh',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'php', 'phtml', 'php3', 'php4',
        'php5', 'php7', 'phps', 'phar', 'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl',
        'py', 'rb', 'app', 'apk', 'deb', 'rpm', 'reg', 'lnk', 'hta', 'wsf', 'gadget',
    ];

    /**
     * Valida un archivo de OBRA (pdf/epub/docx/txt). Devuelve la extensión
     * canónica normalizada. Lanza ValidationException si no es válido.
     */
    public function validateWork(UploadedFile $file, string $field = 'file', ?User $creator = null): string
    {
        $this->assertUploadedOk($file, $field);
        $this->assertNotBlocked($file, $field);

        $ext = $this->normalizedExtension($file);
        if (! in_array($ext, self::WORK_EXTS, true)) {
            $this->fail($field, 'Formato no permitido. Solo PDF, EPUB, DOCX o TXT.');
        }

        // Límite por tipo (TXT 5 · DOCX 25 · EPUB 50 · PDF 100 MB). Para EPUB/PDF,
        // los creadores premium (verificado/destacado/Plus) suben a su tope de plan.
        [$max, $human] = $this->workLimitFor($ext, $creator);
        $this->assertSize($file, $max, $field, $human);

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, self::WORK_MIMES, true)) {
            $this->fail($field, 'El contenido del archivo no coincide con un documento válido.');
        }

        // Magic bytes: lo que realmente impide pasar un .exe renombrado.
        if (! $this->matchesWorkSignature($file, $ext)) {
            $this->fail($field, 'El archivo parece dañado o no es realmente un '.strtoupper($ext).'.');
        }

        return $ext;
    }

    /**
     * Valida un archivo para el IMPORTADOR de capítulos. Aplica TODA la
     * seguridad de obras (MIME + magic bytes + blocklist + tamaño por tipo) y
     * además exige que sea importable a texto: TXT, DOCX o EPUB (PDF no).
     * Para EPUB/DOCX comprueba además que el ZIP no sea una bomba.
     */
    public function validateImport(UploadedFile $file, string $field = 'file', ?User $creator = null): string
    {
        $ext = $this->validateWork($file, $field, $creator);

        if (! in_array($ext, self::IMPORT_EXTS, true)) {
            $this->fail($field, 'Solo se pueden importar archivos TXT, DOCX o EPUB.');
        }

        // EPUB/DOCX son ZIP: verifica límites de descompresión (anti zip-bomb).
        if ($ext === 'epub' || $ext === 'docx') {
            $this->assertSafeArchive($file->getRealPath(), $field);
        }

        return $ext;
    }

    /**
     * Límite de tamaño efectivo para un archivo de obra según su tipo y el plan
     * del creador. TXT/DOCX nunca suben (texto). EPUB/PDF: los creadores premium
     * (verificado/destacado/Plus) reciben el tope de su plan (500 MB / 1 GB).
     *
     * @return array{0:int,1:string} [bytes, texto-humano]
     */
    private function workLimitFor(string $ext, ?User $creator): array
    {
        $base = self::WORK_MAX_BYTES_BY_EXT[$ext] ?? self::WORK_MAX_BYTES;
        $human = self::WORK_MAX_HUMAN_BY_EXT[$ext] ?? $this->human($base);

        if ($creator
            && in_array($ext, ['epub', 'pdf'], true)
            && ($creator->creator_level === 'verified'
                || $creator->creator_level === 'featured'
                || Entitlements::isPremiumPlus($creator))) {
            $tier = $this->premiumWorkMaxBytes($creator);
            if ($tier > $base) {
                return [$tier, $this->human($tier)];
            }
        }

        return [$base, $human];
    }

    /**
     * Protección anti zip-bomb: rechaza contenedores ZIP (EPUB/DOCX) con
     * demasiadas entradas o un tamaño descomprimido desproporcionado.
     */
    public function assertSafeArchive(string $path, string $field = 'file'): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail($field, 'El archivo comprimido está dañado o no se puede leer.');
        }

        if ($zip->numFiles > self::ARCHIVE_MAX_ENTRIES) {
            $zip->close();
            $this->fail($field, 'El archivo contiene demasiados elementos internos.');
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $totalUncompressed += (int) ($stat['size'] ?? 0);
            if ($totalUncompressed > self::ARCHIVE_MAX_UNCOMPRESSED) {
                $zip->close();
                $this->fail($field, 'El archivo descomprimido es demasiado grande.');
            }
        }

        $zip->close();
    }

    /**
     * Carga XML de forma segura (anti-XXE) para parsers de EPUB (OPF/NCX/nav)
     * o DOCX. Rechaza definiciones de entidad y deshabilita el acceso a red.
     * NOTA: no se usa LIBXML_NOENT, así que las entidades no se expanden.
     */
    public function loadXmlSafe(string $xml, string $field = 'file'): \DOMDocument
    {
        // Bloquea el vector XXE más común: definición de entidades personalizadas.
        if (preg_match('/<!ENTITY/i', $xml)) {
            $this->fail($field, 'El XML del archivo contiene entidades no permitidas.');
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET evita cualquier acceso de red al resolver el documento.
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $this->fail($field, 'No se pudo leer el XML del archivo.');
        }

        return $dom;
    }

    /**
     * Valida una IMAGEN (jpg/jpeg/png/webp). Devuelve la extensión canónica
     * (jpeg → jpg). Lanza ValidationException si no es válido.
     */
    public function validateImage(UploadedFile $file, string $field = 'image', ?int $maxBytes = null, ?int $maxDim = null): string
    {
        $maxBytes ??= self::IMAGE_MAX_BYTES;
        $this->assertUploadedOk($file, $field);
        $this->assertNotBlocked($file, $field);
        $this->assertSize($file, $maxBytes, $field, $this->human($maxBytes));

        $ext = $this->normalizedExtension($file);
        if (! in_array($ext, self::IMAGE_EXTS, true)) {
            $this->fail($field, 'La imagen debe ser JPG, PNG o WebP.');
        }

        $mime = $file->getMimeType() ?: '';
        if (! in_array($mime, self::IMAGE_MIMES, true)) {
            $this->fail($field, 'El contenido no es una imagen JPG, PNG o WebP válida.');
        }

        // getimagesize confirma que es una imagen real decodificable (no un
        // payload con cabecera falsa). Bloquea SVG/HTML disfrazados.
        $info = @getimagesize($file->getRealPath());
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($info === false || ! in_array($info[2] ?? 0, $allowed, true)) {
            $this->fail($field, 'La imagen está dañada o no es un formato soportado.');
        }

        if ($maxDim !== null && (($info[0] ?? 0) > $maxDim || ($info[1] ?? 0) > $maxDim)) {
            $this->fail($field, "La imagen supera la resolución máxima de {$maxDim}×{$maxDim} px.");
        }

        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    /**
     * Valida un cómic en CBZ/ZIP de imágenes y devuelve la lista ORDENADA de
     * páginas válidas (nombre interno + extensión + dimensiones). NO devuelve los
     * bytes (memoria acotada): el controlador re-extrae cada entrada por nombre y
     * la guarda de una en una. Aplica todas las capas de seguridad:
     *  - extensión cbz/zip + MIME real + magic bytes PK
     *  - tamaño máximo (según plan del creador)
     *  - escaneo profundo: SOLO imágenes; rechaza ejecutables/scripts internos
     *  - anti zip-bomb: nº de entradas, tamaño por entrada y total descomprimido
     *  - anti path-traversal en nombres internos
     *  - cada imagen verificada real (cabecera) + tope de resolución 8000×8000
     *  - tope de páginas (3000) para no tumbar el servidor
     *
     * @return array<int, array{name:string, ext:string, width:?int, height:?int}>
     */
    public function validateComicArchive(UploadedFile $file, int $maxBytes, string $field = 'file'): array
    {
        $this->assertUploadedOk($file, $field);
        $this->assertNotBlocked($file, $field);
        $this->assertSize($file, $maxBytes, $field, $this->human($maxBytes));

        $ext = $this->normalizedExtension($file);
        if (! in_array($ext, self::COMIC_ARCHIVE_EXTS, true)) {
            $this->fail($field, 'El cómic debe ser un archivo CBZ o ZIP de imágenes.');
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, self::COMIC_ARCHIVE_MIMES, true)) {
            $this->fail($field, 'El contenido no es un archivo ZIP/CBZ válido.');
        }

        $head = @file_get_contents($file->getRealPath(), false, null, 0, 4);
        if ($head === false || ! (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06") || str_starts_with($head, "PK\x07\x08"))) {
            $this->fail($field, 'El archivo no es realmente un ZIP/CBZ.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->fail($field, 'El archivo comprimido está dañado o no se puede leer.');
        }
        if ($zip->numFiles > self::ARCHIVE_MAX_ENTRIES) {
            $zip->close();
            $this->fail($field, 'El archivo contiene demasiados elementos internos.');
        }

        $images = [];
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) {
                continue;
            }
            // Anti path-traversal (../, rutas absolutas, unidades Windows).
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
                $zip->close();
                $this->fail($field, 'El archivo contiene rutas internas no permitidas.');
            }
            $base = basename($name);
            if ($base === '' || str_starts_with($base, '.')) {
                continue;
            }
            $innerExt = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            // Ejecutable/script dentro del archivo → rechazar TODO el cómic.
            if (in_array($innerExt, self::BLOCKED_EXTS, true)) {
                $zip->close();
                $this->fail($field, 'El archivo contiene un elemento no permitido por seguridad.');
            }
            // Solo imágenes; otros archivos (p. ej. ComicInfo.xml) se ignoran.
            if (! in_array($innerExt, self::COMIC_IMAGE_EXTS, true)) {
                continue;
            }
            $size = (int) ($stat['size'] ?? 0);
            if ($size > self::COMIC_IMAGE_MAX_BYTES) {
                $zip->close();
                $this->fail($field, 'Una imagen del cómic supera el máximo de 20 MB.');
            }
            $totalUncompressed += $size;
            if ($totalUncompressed > self::COMIC_ARCHIVE_MAX_UNCOMPRESSED) {
                $zip->close();
                $this->fail($field, 'El contenido descomprimido del cómic es demasiado grande.');
            }
            if (count($images) >= self::MAX_COMIC_PAGES) {
                $zip->close();
                $this->fail($field, 'El cómic supera el máximo de '.self::MAX_COMIC_PAGES.' páginas.');
            }
            // Lee la entrada (≤20 MB en memoria) y confirma que es una imagen real.
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false) {
                $zip->close();
                $this->fail($field, 'No se pudo leer una imagen del archivo.');
            }
            $info = @getimagesizefromstring($bytes);
            if ($info === false || ! in_array($info[2] ?? 0, self::COMIC_IMAGE_TYPES, true)) {
                $zip->close();
                $this->fail($field, 'El archivo contiene un elemento que no es una imagen válida.');
            }
            if (($info[0] ?? 0) > self::MAX_IMAGE_DIM || ($info[1] ?? 0) > self::MAX_IMAGE_DIM) {
                $zip->close();
                $this->fail($field, 'Una imagen supera la resolución máxima de '.self::MAX_IMAGE_DIM.'×'.self::MAX_IMAGE_DIM.' px.');
            }
            unset($bytes);
            $images[] = [
                'name' => $name,
                'ext' => $innerExt === 'jpeg' ? 'jpg' : $innerExt,
                'width' => $info[0] ?? null,
                'height' => $info[1] ?? null,
            ];
        }
        $zip->close();

        if (empty($images)) {
            $this->fail($field, 'El archivo no contiene imágenes válidas.');
        }

        // Orden de lectura natural por nombre (página-001, página-002, …).
        usort($images, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $images;
    }

    private function human(int $bytes): string
    {
        return round($bytes / (1024 * 1024)).' MB';
    }

    /**
     * Valida una imagen recibida como data URL base64 (portadas del wizard).
     * Devuelve [binario, extensión canónica] o lanza ValidationException.
     */
    public function validateImageDataUrl(string $raw, string $field = 'cover'): array
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/s', $raw, $m)) {
            $this->fail($field, 'La portada debe ser una imagen JPG, PNG o WebP.');
        }

        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $binary = base64_decode($m[2], true);
        if ($binary === false || $binary === '') {
            $this->fail($field, 'No se pudo leer la imagen.');
        }
        if (strlen($binary) > self::IMAGE_MAX_BYTES) {
            $this->fail($field, 'La portada no puede pesar más de 10 MB.');
        }

        // Verifica que el contenido decodificado sea realmente una imagen.
        $info = @getimagesizefromstring($binary);
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($info === false || ! in_array($info[2] ?? 0, $allowed, true)) {
            $this->fail($field, 'La portada no es una imagen válida.');
        }

        if (($info[0] ?? 0) > self::COVER_MAX_DIM || ($info[1] ?? 0) > self::COVER_MAX_DIM) {
            $this->fail($field, 'La portada supera la resolución máxima de '.self::COVER_MAX_DIM.'×'.self::COVER_MAX_DIM.' px.');
        }

        return [$binary, $ext];
    }

    // ===================== Helpers internos =====================

    private function assertUploadedOk(UploadedFile $file, string $field): void
    {
        if (! $file->isValid()) {
            $this->fail($field, 'La subida del archivo falló. Inténtalo de nuevo.');
        }
    }

    private function assertSize(UploadedFile $file, int $maxBytes, string $field, string $human): void
    {
        if ($file->getSize() > $maxBytes) {
            $this->fail($field, "El archivo no puede pesar más de {$human}.");
        }
    }

    /** Rechaza extensiones peligrosas en el nombre original, el inferido o cualquier segmento. */
    private function assertNotBlocked(UploadedFile $file, string $field): void
    {
        $clientExt = strtolower($file->getClientOriginalExtension());
        $guessedExt = strtolower((string) $file->guessExtension());

        // Extensión DECLARADA por el cliente: bloquea cualquier peligrosa.
        if ($clientExt !== '' && in_array($clientExt, self::BLOCKED_EXTS, true)) {
            $this->fail($field, 'Ese tipo de archivo no está permitido por seguridad.');
        }

        // Extensión ADIVINADA del contenido: bloquea peligrosas, PERO ignora el
        // genérico "bin" (application/octet-stream). Muchos formatos legítimos
        // tipo ZIP/OOXML (DOCX, EPUB) se detectan como octet-stream y NO deben
        // rechazarse aquí: el control real de contenido es el allowlist de MIME
        // + los magic bytes posteriores (un .exe renombrado igual cae ahí).
        if ($guessedExt !== '' && $guessedExt !== 'bin' && in_array($guessedExt, self::BLOCKED_EXTS, true)) {
            $this->fail($field, 'Ese tipo de archivo no está permitido por seguridad.');
        }

        // Doble extensión tipo "foo.php.pdf": revisa todos los segmentos del nombre.
        $name = strtolower($file->getClientOriginalName());
        foreach (explode('.', $name) as $segment) {
            if (in_array($segment, self::BLOCKED_EXTS, true)) {
                $this->fail($field, 'Ese tipo de archivo no está permitido por seguridad.');
            }
        }
    }

    /**
     * Extensión canónica = la DECLARADA por el cliente (es lo que el usuario
     * elige subir). Así, un .rtf o .odt no se "cuela" como txt por inferencia.
     * El contenido se valida después por MIME + magic bytes. Solo si el nombre
     * no trae extensión se infiere del contenido como último recurso.
     */
    private function normalizedExtension(UploadedFile $file): string
    {
        $client = strtolower($file->getClientOriginalExtension());
        if ($client !== '') {
            return $client;
        }

        return strtolower((string) $file->guessExtension());
    }

    /** Comprueba la firma de bytes (magic number) del archivo de obra. */
    private function matchesWorkSignature(UploadedFile $file, string $ext): bool
    {
        $head = @file_get_contents($file->getRealPath(), false, null, 0, 8);
        if ($head === false || $head === '') {
            return false;
        }

        return match ($ext) {
            'pdf' => str_starts_with($head, '%PDF-'),
            // EPUB y DOCX son ZIP: firma PK\x03\x04 (o variantes vacío/spanned).
            'epub', 'docx' => str_starts_with($head, "PK\x03\x04")
                || str_starts_with($head, "PK\x05\x06")
                || str_starts_with($head, "PK\x07\x08"),
            // TXT: sin firma binaria; rechaza si contiene byte NUL (binario/ejecutable).
            'txt' => ! str_contains($head, "\0"),
            default => false,
        };
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
