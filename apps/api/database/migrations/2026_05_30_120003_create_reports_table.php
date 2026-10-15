<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            // Polymorphic target — qué se reporta
            // target_type: book | review | comment | user | salon_post | work | chapter
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');

            // Razón
            $table->string('reason', 30);
            // spam | abuse | copyright | illegal | inappropriate | misleading | other

            $table->text('details')->nullable();

            // Estado
            $table->string('status', 20)->default('open');
            // open | in_review | resolved | dismissed

            // Resolución
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->string('resolution_action', 30)->nullable();
            // none | warn | hide | delete | suspend_temp | suspend_perm | ban

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index('status');
            $table->index(['status', 'created_at']);
            $table->index('reporter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
