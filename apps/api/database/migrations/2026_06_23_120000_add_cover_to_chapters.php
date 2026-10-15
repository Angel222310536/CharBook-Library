<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portada (miniatura) por capítulo/episodio — para mostrar los episodios como
 * tarjetas con imagen en el constructor y en la tira de episodios del lector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('file_size');
            $table->string('cover_url')->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'cover_url']);
        });
    }
};
