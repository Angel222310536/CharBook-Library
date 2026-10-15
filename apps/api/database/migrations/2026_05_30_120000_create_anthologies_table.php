<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anthologies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title', 120);
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['is_public', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anthologies');
    }
};
