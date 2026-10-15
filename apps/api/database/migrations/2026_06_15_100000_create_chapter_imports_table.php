<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de importación de libros (EPUB/DOCX/TXT → capítulos reales).
 *
 * Es un sistema NUEVO y aislado: no toca `chapters` ni el flujo de archivos
 * adjuntos. Persiste el estado del flujo "previsualizar → confirmar" y los
 * datos para el resumen final que ve el creador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // uploaded → parsing → preview_ready → importing → done | failed | canceled
            $table->string('status', 20)->default('uploaded');

            // Archivo de origen (en disco privado `local`, nunca público).
            $table->string('source_name', 255)->nullable();
            $table->string('source_ext', 10)->nullable();
            $table->string('source_path', 500)->nullable();
            $table->unsignedBigInteger('source_size')->nullable();

            // Vista previa (metadatos por capítulo, sin el contenido completo).
            $table->json('detected')->nullable();
            // Ruta del JSON con el contenido parseado completo (para confirmar
            // sin re-parsear). En disco privado, no en la BD.
            $table->string('parsed_path', 500)->nullable();
            // Opciones elegidas al confirmar (p. ej. { publish: bool }).
            $table->json('options')->nullable();

            // Progreso + resumen final.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedBigInteger('word_count')->default(0);
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'status']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_imports');
    }
};
