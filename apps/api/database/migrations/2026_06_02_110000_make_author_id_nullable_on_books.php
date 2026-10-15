<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las obras subidas por creadores (modelo abierto) NO requieren un Author
 * registrado: el creator_id ya identifica al autor real. Solo las cargas
 * editoriales/clásicas usan Author (Borges, Cortázar…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable(false)->change();
        });
    }
};
