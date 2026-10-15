<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            // Información básica
            $table->string('title', 255);
            $table->string('slug', 280)->unique();
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('synopsis')->nullable();

            // Relación con autor
            $table->foreignId('author_id')
                  ->constrained('authors')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            // Archivos
            $table->string('cover_url')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_type', 10)->default('pdf'); // pdf|epub|both
            $table->unsignedBigInteger('file_size')->nullable();

            // Monetización
            $table->string('access_type', 10)->default('free'); // free|premium
            $table->decimal('price', 8, 2)->nullable();

            // Metadata externa (Google Books / Open Library)
            $table->string('isbn_10', 13)->nullable()->index();
            $table->string('isbn_13', 17)->nullable()->index();
            $table->string('google_books_id')->nullable()->index();
            $table->string('open_library_id')->nullable()->index();

            // Metadata editorial
            $table->string('publisher', 150)->nullable();
            $table->smallInteger('publication_year')->nullable();
            $table->string('language', 10)->default('es');
            $table->unsignedSmallInteger('pages')->nullable();

            // Stats denormalizadas
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->unsignedInteger('favorites_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            // Estados
            $table->boolean('is_published')->default(true);
            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Índices para listados rápidos
            $table->index('slug');
            $table->index('access_type');
            $table->index('is_published');
            $table->index('is_featured');
            $table->index(['is_published', 'created_at']);

            // Nota: la búsqueda full-text la haremos con Meilisearch (Fase 3)
            // así que no añadimos índice tsvector ahora.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
