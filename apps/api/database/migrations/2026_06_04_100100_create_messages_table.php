<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de mensajes (centro de comunicaciones).
 * NO es chat — mensajes oficiales del staff, resúmenes, logros, eventos, consejos.
 *
 *   category: review | report | achievement | event | tip | platform
 *   priority: low | normal | high | urgent
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('priority', 20)->default('normal');
            $table->string('subject', 200);
            $table->text('body');
            $table->string('sender_name', 100)->default('CharBook Library');
            $table->string('sender_role', 50)->nullable(); // staff, system, admin
            $table->json('attachments')->nullable();
            $table->json('actions')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('important_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'important_at']);
            $table->index(['user_id', 'archived_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
