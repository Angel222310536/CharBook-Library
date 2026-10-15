<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos para que los creadores publiquen sus obras directamente,
 * sin pasar por la admin app (modelo abierto / autoservicio).
 *
 *   content_type     → novela | cuento | ensayo | poesia | no_ficcion | experimental
 *   age_rating       → all | teen | mature | explicit
 *   tags             → JSON array de strings libres (separadas del taxonomy de categories)
 *   copyright_holder → texto libre, p.ej. "© 2026 Nombre del autor"
 *   is_original      → bool, confirmación del autor de que la obra es original
 *   submission_status→ draft | review | published   (gate para listar en catálogo)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('content_type', 30)->nullable()->after('synopsis');
            $table->string('age_rating', 20)->default('all')->after('content_type');
            $table->json('tags')->nullable()->after('age_rating');
            $table->string('copyright_holder', 200)->nullable()->after('tags');
            $table->boolean('is_original')->default(false)->after('copyright_holder');
            $table->string('submission_status', 20)->default('draft')->after('is_original');

            $table->index(['content_type', 'is_published']);
            $table->index(['submission_status']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['content_type', 'is_published']);
            $table->dropIndex(['submission_status']);
            $table->dropColumn([
                'content_type', 'age_rating', 'tags',
                'copyright_holder', 'is_original', 'submission_status',
            ]);
        });
    }
};
