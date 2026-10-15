<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comic_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('image_url', 1000)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comic_pages');
    }
};
