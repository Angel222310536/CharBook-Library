<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Una persona cuenta una sola vez como "compartió"
            $table->unique(['activity_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_shares');
    }
};
