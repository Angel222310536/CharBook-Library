<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistema de notificaciones (eventos rápidos).
 *
 *   category: reading | comments | followers | works | system | monetization
 *   type:     chapter_new | work_new | comment_reply | comment_like |
 *             work_followed_update | trending | new_follower | mentioned |
 *             system_news | platform_update | work_approved | work_rejected |
 *             work_suspended | review_received | favorite_received | etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('type', 50);
            $table->string('emoji', 8)->nullable();
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->string('link', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
