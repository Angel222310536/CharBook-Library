<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widgets que un creador puede mostrar en su perfil público.
 *
 * Cubren las 6 secciones pedidas:
 *   - announcement   📣 Anuncios
 *   - tip            💡 Tips de lectura
 *   - event          📅 Eventos
 *   - link           🔗 Acceso directo (link externo destacado)
 *   - new_arrival    📚 Nuevas adquisiciones (libros recomendados)
 *   - featured_cat   🗂️ Categoría destacada
 *
 * Solo creadores aprobados pueden crear widgets — los lee cualquiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Tipo de widget — ver enum arriba
            $table->string('type', 30);

            $table->string('title', 140);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();

            // Para eventos / anuncios con fechas
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Referencias opcionales (para new_arrival y featured_cat)
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Visibilidad y orden
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'type', 'is_published']);
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_widgets');
    }
};
