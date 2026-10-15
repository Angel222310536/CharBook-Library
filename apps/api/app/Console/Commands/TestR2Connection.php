<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestR2Connection extends Command
{
    protected $signature = 'r2:test';
    protected $description = 'Verifica que las credenciales de Cloudflare R2 funcionan correctamente.';

    public function handle(): int
    {
        $this->info('🔍 Probando conexión con Cloudflare R2...');
        $this->newLine();

        $testFile = 'test/charbook-r2-test-' . time() . '.txt';
        $content = "CharBook Library R2 test\n" . now()->toIso8601String();

        // ===== Test bucket de libros (privado) =====
        $this->line('📚 <fg=cyan>Bucket r2_books</> (privado)');
        try {
            Storage::disk('r2_books')->put($testFile, $content);
            $this->info('  ✅ Upload OK');

            $downloaded = Storage::disk('r2_books')->get($testFile);
            $this->info('  ✅ Download OK (' . strlen($downloaded) . ' bytes)');

            $signedUrl = Storage::disk('r2_books')->temporaryUrl($testFile, now()->addMinutes(5));
            $this->info('  ✅ URL firmada generada');
            $this->line('     <fg=gray>' . substr($signedUrl, 0, 80) . '...</>');

            Storage::disk('r2_books')->delete($testFile);
            $this->info('  ✅ Delete OK');
        } catch (\Throwable $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();

        // ===== Test bucket de portadas (público) =====
        $this->line('🖼️  <fg=cyan>Bucket r2_covers</> (público)');
        try {
            Storage::disk('r2_covers')->put($testFile, $content);
            $this->info('  ✅ Upload OK');

            $publicUrl = Storage::disk('r2_covers')->url($testFile);
            $this->info('  ✅ URL pública: <fg=gray>' . $publicUrl . '</>');

            // Verifica que la URL pública responde
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $remote = @file_get_contents($publicUrl, false, $ctx);
            if ($remote === $content) {
                $this->info('  ✅ URL pública verificada (contenido coincide)');
            } else {
                $this->warn('  ⚠️  La URL pública no devolvió el contenido esperado. ¿Habilitaste "Allow Access" del subdomain r2.dev?');
            }

            Storage::disk('r2_covers')->delete($testFile);
            $this->info('  ✅ Delete OK');
        } catch (\Throwable $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('🎉 R2 está configurado correctamente.');
        return self::SUCCESS;
    }
}
