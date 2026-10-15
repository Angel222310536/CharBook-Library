<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Hash de IP (privacy-friendly: no guardamos la IP real)
            $table->string('ip_hash', 64)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['affiliate_link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_clicks');
    }
};
