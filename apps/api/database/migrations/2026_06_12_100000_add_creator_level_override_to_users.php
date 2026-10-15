<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override explícito del nivel económico del creador.
 *
 * Hasta ahora `creator_level` era SOLO un accessor derivado (de creator_tier +
 * rol + obras publicadas). Esta columna permite que un admin fije el nivel
 * económico de forma explícita (basic/verified/featured) — la fuente única del
 * reparto de ingresos. Si queda NULL, el accessor sigue derivándolo como antes
 * (sin regresión para usuarios existentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('creator_level', 20)->nullable()->after('creator_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('creator_level');
        });
    }
};
