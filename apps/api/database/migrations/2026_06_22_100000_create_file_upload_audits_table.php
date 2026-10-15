<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría de subidas de archivos (cómics, obras, portadas).
 * Guarda quién subió qué, el MIME real detectado, tamaño y resultado de la
 * validación — para investigaciones posteriores. NUNCA guarda el archivo aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_upload_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('kind', 32);                 // comic_archive | comic_page | work | cover
            $table->string('original_name', 255)->nullable();
            $table->string('declared_ext', 16)->nullable();
            $table->string('detected_mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('result', 16);               // accepted | rejected
            $table->text('reason')->nullable();         // motivo del rechazo / nº de páginas, etc.
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_upload_audits');
    }
};
