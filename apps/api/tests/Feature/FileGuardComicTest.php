<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FileGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FileGuardComicTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1×1 real (cabecera válida para getimagesizefromstring). */
    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** @param array<string,string> $entries name => content */
    private function zipUpload(array $entries, string $name = 'comic.cbz'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbz').'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('No se pudo crear el ZIP de prueba.');
        }
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);

        return UploadedFile::fake()->createWithContent($name, $bin);
    }

    public function test_valid_cbz_returns_images_in_reading_order(): void
    {
        $up = $this->zipUpload([
            'p-002.png' => $this->png(),
            'p-001.png' => $this->png(),
            'p-010.png' => $this->png(),
        ]);

        $images = app(FileGuard::class)->validateComicArchive($up, 250 * 1024 * 1024);

        $this->assertCount(3, $images);
        $this->assertSame(['p-001.png', 'p-002.png', 'p-010.png'], array_column($images, 'name'));
    }

    public function test_cbz_with_executable_inside_is_rejected(): void
    {
        $up = $this->zipUpload(['page.png' => $this->png(), 'evil.exe' => 'MZ malicious']);

        $this->expectException(ValidationException::class);
        app(FileGuard::class)->validateComicArchive($up, 250 * 1024 * 1024);
    }

    public function test_cbz_with_a_fake_image_is_rejected(): void
    {
        // .png que en realidad es texto (no pasa getimagesizefromstring).
        $up = $this->zipUpload(['page.png' => 'no soy una imagen']);

        $this->expectException(ValidationException::class);
        app(FileGuard::class)->validateComicArchive($up, 250 * 1024 * 1024);
    }

    public function test_cbz_skips_non_images_but_keeps_pages(): void
    {
        $up = $this->zipUpload(['ComicInfo.xml' => '<ComicInfo/>', 'page.png' => $this->png()]);

        $images = app(FileGuard::class)->validateComicArchive($up, 250 * 1024 * 1024);

        $this->assertCount(1, $images);
    }

    public function test_cbz_without_images_is_rejected(): void
    {
        $up = $this->zipUpload(['readme.txt' => 'hola']);

        $this->expectException(ValidationException::class);
        app(FileGuard::class)->validateComicArchive($up, 250 * 1024 * 1024);
    }

    public function test_premium_size_limits_by_level_and_plan(): void
    {
        $g = app(FileGuard::class);
        $normal = User::factory()->create(['creator_level' => 'basic', 'subscription_tier' => 'free']);
        $verified = User::factory()->create(['creator_level' => 'verified', 'subscription_tier' => 'free']);
        $featured = User::factory()->create(['creator_level' => 'featured', 'subscription_tier' => 'free']);
        $plus = User::factory()->create(['creator_level' => 'basic', 'subscription_tier' => 'premium_plus']);

        $this->assertSame(250 * 1024 * 1024, $g->premiumWorkMaxBytes($normal));
        $this->assertSame(500 * 1024 * 1024, $g->premiumWorkMaxBytes($verified));
        $this->assertSame(1024 * 1024 * 1024, $g->premiumWorkMaxBytes($featured));
        $this->assertSame(1024 * 1024 * 1024, $g->premiumWorkMaxBytes($plus));
    }
}
