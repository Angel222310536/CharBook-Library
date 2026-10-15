<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier visual del creador, independiente del flujo de "creator_status".
 *
 *  - none      → creador básico (cualquier user que publica obras)
 *  - verified  → tiene insignia ✓ verde (revisado por staff)
 *  - featured  → "Autor Destacado" — tier dorado curatorial
 *
 * Esto se separa del creator_status del flujo de aprobación: hoy creator_status
 * solo cubre verified (approved). Featured es un nivel adicional que solo
 * super_admin puede asignar manualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('creator_tier', 20)->default('none')->after('creator_status');
            $table->index('creator_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['creator_tier']);
            $table->dropColumn('creator_tier');
        });
    }
};
