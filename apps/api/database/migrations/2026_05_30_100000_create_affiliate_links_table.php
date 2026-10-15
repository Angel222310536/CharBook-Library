<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Vendor: amazon, kobo, audible, apple_books, google_play, other
            $table->string('vendor', 30);

            // URL completa con tag de afiliado ya incluido
            $table->string('url', 1000);

            // Label opcional (ej: "Edición Tapa Dura $24.99")
            $table->string('label', 100)->nullable();

            // Para ordenar manualmente (menor = primero)
            $table->unsignedSmallInteger('position')->default(0);

            $table->boolean('is_active')->default(true);

            // Contador denormalizado (más rápido que JOIN a clicks)
            $table->unsignedInteger('click_count')->default(0);

            $table->timestamps();

            $table->index(['book_id', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_links');
    }
};
