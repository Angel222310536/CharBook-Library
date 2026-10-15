<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('rating'); // 1..5
            $table->string('title', 200)->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            // Una reseña por usuario por libro (puede actualizar)
            $table->unique(['user_id', 'book_id']);
            $table->index(['book_id', 'created_at']);
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
