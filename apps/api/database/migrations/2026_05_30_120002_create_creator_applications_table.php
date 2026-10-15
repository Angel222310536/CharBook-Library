<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Estado
            $table->string('status', 25)->default('pending');
            // pending | changes_requested | approved | rejected

            // Datos del solicitante
            $table->string('display_name', 100);
            $table->string('pen_name', 100)->nullable();
            $table->text('bio_long');                            // Bio extendida (500-2000 chars)
            $table->string('sample_url', 500);                   // Link a una obra ejemplo
            $table->json('genres');                              // ['fantasia', 'ciencia_ficcion']
            $table->json('languages');                           // ['es', 'en']
            $table->text('why_join');                            // 250-1000 chars
            $table->json('social_links')->nullable();            // {twitter, instagram, web, ...}

            // Plan de publicación
            $table->string('publish_frequency', 30)->nullable(); // 'weekly', 'biweekly', 'monthly', 'irregular'
            $table->text('publish_plan')->nullable();            // Texto libre

            // Revisión (rúbrica 0-3 por eje, max 12)
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('score_quality')->nullable();        // 0-3
            $table->unsignedTinyInteger('score_commitment')->nullable();     // 0-3
            $table->unsignedTinyInteger('score_professionalism')->nullable();// 0-3
            $table->unsignedTinyInteger('score_fit')->nullable();            // 0-3
            $table->unsignedTinyInteger('score_total')->nullable();          // 0-12
            $table->text('reviewer_notes')->nullable();          // Feedback obligatorio si rechazo/changes

            // Timestamps
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Un usuario solo puede tener UNA solicitud activa (pending/changes_requested)
            // No usamos unique simple porque puede tener varias rejected históricas
            $table->index('user_id');
            $table->index('status');
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_applications');
    }
};
