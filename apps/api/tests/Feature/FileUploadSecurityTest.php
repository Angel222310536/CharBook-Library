<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\FileGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Seguridad de subida de archivos: el núcleo es que un ejecutable/script
 * renombrado a una extensión permitida DEBE rechazarse (magic bytes + MIME).
 */
class FileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private FileGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(FileGuard::class);
        Storage::fake('public');
    }

    // ---------- Helpers para crear archivos con contenido real ----------

    private function fakeWith(string $name, string $content, ?string $mime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fg');
        file_put_contents($path, $content);
        return new UploadedFile($path, $name, $mime, null, true); // test mode
    }

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    private const ZIP = "PK\x03\x04\x14\x00\x00\x00\x00\x00";   // firma ZIP (epub/docx)
    private const EXE = "MZ\x90\x00\x03\x00\x00\x00";            // firma PE/EXE
    private const ELF = "\x7fELF\x02\x01\x01\x00";               // firma ELF (linux bin)

    // =========================================================
    // OBRAS
    // =========================================================

    public function test_acepta_pdf_valido(): void
    {
        $ext = $this->guard->validateWork($this->fakeWith('libro.pdf', self::PDF));
        $this->assertSame('pdf', $ext);
    }

    public function test_acepta_txt_valido(): void
    {
        $ext = $this->guard->validateWork($this->fakeWith('cap.txt', "Capitulo uno\nTexto plano."));
        $this->assertSame('txt', $ext);
    }

    public function test_acepta_epub_valido(): void
    {
        // EPUB real = ZIP con entrada "mimetype". Construido con ZipArchive
        // para que finfo lo reconozca como application/epub+zip / zip.
        $zipPath = tempnam(sys_get_temp_dir(), 'epub') . '.epub';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container/>');
        $zip->close();

        $ext = $this->guard->validateWork(new UploadedFile($zipPath, 'libro.epub', null, null, true));
        $this->assertSame('epub', $ext);
    }

    /** EL CASO CRÍTICO: virus.exe renombrado a libro.pdf debe rechazarse. */
    public function test_rechaza_exe_renombrado_a_pdf(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateWork($this->fakeWith('libro.pdf', self::EXE));
    }

    public function test_rechaza_elf_renombrado_a_epub(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateWork($this->fakeWith('libro.epub', self::ELF));
    }

    public function test_rechaza_extension_peligrosa_directa(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateWork($this->fakeWith('virus.exe', self::EXE));
    }

    public function test_rechaza_doble_extension_php_pdf(): void
    {
        // Contenido PDF válido pero el nombre lleva .php → blocklist lo corta.
        $this->expectException(ValidationException::class);
        $this->guard->validateWork($this->fakeWith('shell.php.pdf', self::PDF));
    }

    public function test_rechaza_txt_con_bytes_binarios(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateWork($this->fakeWith('payload.txt', "texto\x00\x01binario"));
    }

    public function test_rechaza_extension_no_permitida(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateWork($this->fakeWith('archivo.rtf', 'contenido'));
    }

    // =========================================================
    // IMÁGENES
    // =========================================================

    public function test_acepta_png_real(): void
    {
        // PNG real de 1x1 px (bytes embebidos, sin depender de la extensión GD).
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQAY3Y2wAAAAAElFTkSuQmCC'
        );
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($tmp, $png);
        $ext = $this->guard->validateImage(new UploadedFile($tmp, 'portada.png', 'image/png', null, true));
        $this->assertSame('png', $ext);
    }

    public function test_rechaza_svg_como_imagen(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateImage($this->fakeWith('portada.svg', '<svg onload="alert(1)"></svg>'));
    }

    public function test_rechaza_html_renombrado_a_png(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateImage($this->fakeWith('portada.png', '<html><script>alert(1)</script></html>'));
    }

    public function test_rechaza_exe_renombrado_a_jpg(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateImage($this->fakeWith('foto.jpg', self::EXE));
    }

    // =========================================================
    // DATA URL (portada base64 del wizard)
    // =========================================================

    public function test_data_url_rechaza_contenido_no_imagen(): void
    {
        $this->expectException(ValidationException::class);
        // prefijo válido pero el base64 es código, no una imagen
        $this->guard->validateImageDataUrl('data:image/png;base64,' . base64_encode('<?php system($_GET[0]); ?>'));
    }

    public function test_data_url_rechaza_svg(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->validateImageDataUrl('data:image/svg+xml;base64,' . base64_encode('<svg></svg>'));
    }

    // =========================================================
    // E2E sobre el endpoint real de capítulo
    // =========================================================

    public function test_endpoint_capitulo_rechaza_exe_renombrado(): void
    {
        $creator = User::factory()->create();
        $author = Author::create(['name' => 'QA Author']);
        $book = Book::create([
            'title' => 'QA Upload Book', 'description' => 'Fixture', 'author_id' => $author->id,
            'creator_id' => $creator->id, 'access_type' => 'free', 'language' => 'es',
            'is_published' => true, 'work_format' => 'serie',
        ]);
        $chapter = Chapter::create([
            'book_id' => $book->id, 'number' => 1, 'title' => 'Cap 1', 'position' => 1, 'is_published' => false,
        ]);

        $payload = $this->fakeWith('libro.pdf', self::EXE);

        $this->actingAs($creator)
            ->postJson("/api/me/chapters/{$chapter->id}/file", ['file' => $payload])
            ->assertStatus(422);

        $this->assertNull($chapter->fresh()->file_url);
    }

    public function test_endpoint_capitulo_acepta_pdf_real(): void
    {
        $creator = User::factory()->create();
        $author = Author::create(['name' => 'QA Author']);
        $book = Book::create([
            'title' => 'QA Upload Book', 'description' => 'Fixture', 'author_id' => $author->id,
            'creator_id' => $creator->id, 'access_type' => 'free', 'language' => 'es',
            'is_published' => true, 'work_format' => 'serie',
        ]);
        $chapter = Chapter::create([
            'book_id' => $book->id, 'number' => 1, 'title' => 'Cap 1', 'position' => 1, 'is_published' => false,
        ]);

        $payload = $this->fakeWith('cap.pdf', self::PDF);

        $this->actingAs($creator)
            ->postJson("/api/me/chapters/{$chapter->id}/file", ['file' => $payload])
            ->assertOk();

        $this->assertNotNull($chapter->fresh()->file_url);
    }
}
