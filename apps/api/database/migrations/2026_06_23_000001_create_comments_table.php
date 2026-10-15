<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentarios estilo WEBTOON: polimórficos (sirven para una OBRA completa o para
 * un CAPÍTULO/episodio), con una capa de respuestas (parent_id) y «me gusta».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable'); // commentable_type + commentable_id
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_spoiler')->default(false);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id', 'parent_id', 'created_at'], 'comments_target_idx');
        });

        Schema::create('comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('comments');
    }
};
