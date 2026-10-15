<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 170)->unique();
            $table->text('biography')->nullable();
            $table->string('photo_url')->nullable();
            $table->smallInteger('birth_year')->nullable();
            $table->smallInteger('death_year')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->timestamps();

            $table->index('slug');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authors');
    }
};
