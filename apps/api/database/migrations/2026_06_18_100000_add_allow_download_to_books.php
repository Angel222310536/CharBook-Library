<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descarga de obras: el creador decide (obligatorio) si su libro/serie se puede
 * descargar. Si está activado, el lector puede bajar la obra en un solo archivo
 * (EPUB con los capítulos a los que tiene acceso). Por defecto, NO descargable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('allow_download')->default(false)->after('access_type');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('allow_download');
        });
    }
};
