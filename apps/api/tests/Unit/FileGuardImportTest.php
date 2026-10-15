<?php

namespace Tests\Unit;

use App\Services\FileGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase; // boota la app: ValidationException necesita el contenedor

/**
 * Nuevos límites por tipo + utilidades de seguridad del importador
 * (anti zip-bomb y anti-XXE). No deben debilitar nada de lo existente.
 */
class FileGuardImportTest extends TestCase
{
    private FileGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new FileGuard;
    }

    private function fakeWith(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fg');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_limites_por_tipo_configurados(): void
    {
        $this->assertSame(5 * 1024 * 1024, FileGuard::WORK_MAX_BYTES_BY_EXT['txt']);
        $this->assertSame(25 * 1024 * 1024, FileGuard::WORK_MAX_BYTES_BY_EXT['docx']);
        $this->assertSame(50 * 1024 * 1024, FileGuard::WORK_MAX_BYTES_BY_EXT['epub']);
        $this->assertSame(100 * 1024 * 1024, FileGuard::WORK_MAX_BYTES_BY_EXT['pdf']);
        $this->assertSame(10 * 1024 * 1024, FileGuard::IMAGE_MAX_BYTES);
    }

    public function test_txt_dentro_del_limite_ok(): void
    {
        $ext = $this->guard->validateImport($this->fakeWith('libro.txt', "Capítulo 1\nHola."));
        $this->assertSame('txt', $ext);
    }

    public function test_txt_mayor_a_5mb_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        $big = str_repeat('palabra de relleno ', 320000); // ~6 MB de texto real
        $this->guard->validateImport($this->fakeWith('grande.txt', $big));
    }

    public function test_pdf_no_es_importable(): void
    {
        $this->expectException(ValidationException::class);
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $this->guard->validateImport($this->fakeWith('libro.pdf', $pdf));
    }

    public function test_load_xml_safe_lee_xml_normal(): void
    {
        $dom = $this->guard->loadXmlSafe('<?xml version="1.0"?><root><a>ok</a></root>');
        $this->assertSame('ok', $dom->getElementsByTagName('a')->item(0)->nodeValue);
    }

    public function test_load_xml_safe_rechaza_entidades_xxe(): void
    {
        $this->expectException(ValidationException::class);
        $payload = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>';
        $this->guard->loadXmlSafe($payload);
    }

    public function test_assert_safe_archive_rechaza_zip_corrupto(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->assertSafeArchive($this->fakeWith('x.epub', 'no soy un zip')->getRealPath());
    }
}
