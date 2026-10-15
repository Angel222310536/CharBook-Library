<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anthology_book', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anthology_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Orden curatorial dentro de la antología
            $table->unsignedInteger('position')->default(0);
            // "Por qué este libro" — nota del curador
            $table->text('note')->nullable();

            $table->timestamps();

            // Un libro no se repite dentro de la misma antología
            $table->unique(['anthology_id', 'book_id']);
            $table->index(['anthology_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anthology_book');
    }
};
