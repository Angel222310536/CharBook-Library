<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del wizard de creación de obras:
 *  work_format        → serie | libro_completo | comic   (reemplaza content_type viejo)
 *  parent_category_id → top-level del taxonomy
 *  subcategory_id     → hijo del parent_category
 *  schedule_at        → publicación programada
 *  comments_mode      → on | followers_only | off
 *  ratings_enabled    → bool
 *  seo_title          → texto opcional
 *  seo_description    → texto opcional
 *  seo_keywords       → JSON array
 *  author_note_start  → nota inicial del autor
 *  author_note_end    → nota final del autor
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('work_format', 30)->nullable()->after('content_type');
            $table->foreignId('parent_category_id')->nullable()->after('work_format')->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->after('parent_category_id')->constrained('categories')->nullOnDelete();

            $table->timestamp('schedule_at')->nullable()->after('submission_status');

            $table->string('comments_mode', 20)->default('on')->after('schedule_at');
            $table->boolean('ratings_enabled')->default(true)->after('comments_mode');

            $table->string('seo_title', 200)->nullable()->after('ratings_enabled');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->json('seo_keywords')->nullable()->after('seo_description');

            $table->text('author_note_start')->nullable()->after('seo_keywords');
            $table->text('author_note_end')->nullable()->after('author_note_start');

            $table->index(['work_format']);
            $table->index(['schedule_at']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['work_format']);
            $table->dropIndex(['schedule_at']);
            $table->dropConstrainedForeignId('parent_category_id');
            $table->dropConstrainedForeignId('subcategory_id');
            $table->dropColumn([
                'work_format', 'schedule_at', 'comments_mode',
                'ratings_enabled', 'seo_title', 'seo_description',
                'seo_keywords', 'author_note_start', 'author_note_end',
            ]);
        });
    }
};
