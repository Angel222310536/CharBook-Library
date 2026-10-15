<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->string('file_url', 1000)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_type', 20)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('author_note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('schedule_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['book_id', 'position']);
            $table->index(['book_id', 'is_published']);
            $table->index(['schedule_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
