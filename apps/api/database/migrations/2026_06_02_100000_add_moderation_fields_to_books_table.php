<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de moderación + destacados para el panel admin de obras subidas.
 *
 *   is_hidden       → admin oculta la obra (no aparece en catálogo público)
 *   is_sensitive    → marca de "contenido sensible" (warning previo)
 *   mod_notes       → notas internas SOLO visibles para moderadores/admin
 *   is_pinned       → fijada en destacados de home
 *   is_recommended  → marcada por staff como recomendada
 *   is_trending     → marcada como tendencia
 *   hidden_at       → cuándo fue ocultada (auditoría)
 *   hidden_by       → qué admin la ocultó
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_featured');
            $table->boolean('is_sensitive')->default(false)->after('is_hidden');
            $table->text('mod_notes')->nullable()->after('is_sensitive');
            $table->boolean('is_pinned')->default(false)->after('mod_notes');
            $table->boolean('is_recommended')->default(false)->after('is_pinned');
            $table->boolean('is_trending')->default(false)->after('is_recommended');
            $table->timestamp('hidden_at')->nullable()->after('is_trending');
            $table->foreignId('hidden_by')->nullable()->after('hidden_at')->constrained('users')->nullOnDelete();

            $table->index(['is_hidden']);
            $table->index(['is_pinned', 'is_recommended', 'is_trending']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['is_hidden']);
            $table->dropIndex(['is_pinned', 'is_recommended', 'is_trending']);
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn([
                'is_hidden', 'is_sensitive', 'mod_notes',
                'is_pinned', 'is_recommended', 'is_trending',
                'hidden_at',
            ]);
        });
    }
};
