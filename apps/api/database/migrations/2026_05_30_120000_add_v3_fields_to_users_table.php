<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Locale: ISO 639-1 (es, en, pt, fr, de, it, ja)
            $table->string('locale', 5)->default('es')->after('avatar');

            // Trust score (0-100) — usado para creadores, default 25 para nuevos
            $table->unsignedTinyInteger('trust_score')->default(25)->after('locale');

            // Estado del usuario como creador
            // none = nunca aplicó
            // pending = solicitud enviada, esperando revisión
            // changes_requested = revisor pidió cambios
            // approved = es creador activo
            // rejected = rechazado (puede reaplicar tras 30 días)
            // suspended = era creador, fue suspendido
            $table->string('creator_status', 20)->default('none')->after('trust_score');

            $table->index('creator_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['creator_status']);
            $table->dropColumn(['locale', 'trust_score', 'creator_status']);
        });
    }
};
