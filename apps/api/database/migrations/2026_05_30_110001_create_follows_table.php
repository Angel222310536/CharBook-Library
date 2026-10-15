<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            // Quien sigue
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            // A quién sigue
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            // No se puede seguir dos veces a la misma persona
            $table->unique(['follower_id', 'following_id']);
            // Para listar rápido "a quién sigue X" y "quién sigue a X"
            $table->index(['follower_id', 'created_at']);
            $table->index(['following_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
