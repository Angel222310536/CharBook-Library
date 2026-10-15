<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 5)->primary(); // es, en, pt, fr, de, it, ja
            $table->string('name_native', 50);    // Español, English, Português...
            $table->string('name_en', 50);        // Spanish, English, Portuguese
            $table->string('flag_emoji', 10);     // 🇪🇸 🇺🇸 🇧🇷 ...
            $table->boolean('is_active')->default(true);
            $table->boolean('is_ui_supported')->default(false); // Si la UI ya está traducida
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
