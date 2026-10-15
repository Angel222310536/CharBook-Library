<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que las páginas de cómic pertenezcan a un CAPÍTULO (serie de cómic tipo
 * webtoon), no solo a la obra completa. `chapter_id` nulo = páginas a nivel de obra
 * (cómic continuo de un solo bloque, comportamiento previo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comic_pages', function (Blueprint $table) {
            $table->foreignId('chapter_id')->nullable()->after('book_id')
                ->constrained()->cascadeOnDelete();
            $table->index(['chapter_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('comic_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chapter_id');
        });
    }
};
