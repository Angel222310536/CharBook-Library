<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinción importante:
 *   - books.author_id  → Author (entidad bibliográfica clásica, p.ej. "Borges")
 *   - books.creator_id → User aprobado como creator que subió la obra a la plataforma
 *
 * Un libro puede tener ambos (un creador sube una obra de un autor histórico)
 * o solo creator_id (autopublicación del propio creador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('creator_id')
                ->nullable()
                ->after('author_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['creator_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creator_id');
        });
    }
};
