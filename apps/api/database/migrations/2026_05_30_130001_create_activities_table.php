<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // started_reading | finished_reading | want_to_read | reviewed | favorited
            $table->string('type', 32);

            // Casi todas las actividades giran en torno a un libro
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();

            // Datos extra (rating de la review, etc.)
            $table->json('meta')->nullable();

            // Contadores denormalizados para el feed (rendimiento)
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);

            $table->timestamps();

            // Feed por autor y feed global por fecha
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            // Evita duplicar la misma actividad libro+tipo del mismo usuario
            $table->index(['user_id', 'book_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
