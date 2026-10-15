<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->unsignedInteger('total_readings_count')->default(0)->after('views_count');
        });

        DB::table('books')->update([
            'total_readings_count' => DB::raw('views_count'),
            'views_count' => 0,
        ]);

        Schema::create('book_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_hash', 64)->nullable();
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');
            $table->unsignedInteger('sessions_count')->default(1);
            $table->timestamps();

            $table->unique(['book_id', 'user_id']);
            $table->unique(['book_id', 'visitor_hash']);
            $table->index(['book_id', 'last_viewed_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_views');

        DB::table('books')->update([
            'views_count' => DB::raw('total_readings_count'),
        ]);

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('total_readings_count');
        });
    }
};
