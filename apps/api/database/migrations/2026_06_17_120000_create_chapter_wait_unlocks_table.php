<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Esperar para desbloquear": un lector free reserva un capítulo y, tras una
 * espera (configurable, p. ej. 48 h), se desbloquea gratis automáticamente.
 * Es la alternativa gratuita a pagar con monedas. Un registro por user+capítulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_wait_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocks_at'); // cuándo queda disponible gratis
            $table->timestamps();

            $table->unique(['user_id', 'chapter_id']);
            $table->index(['user_id', 'unlocks_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_wait_unlocks');
    }
};
