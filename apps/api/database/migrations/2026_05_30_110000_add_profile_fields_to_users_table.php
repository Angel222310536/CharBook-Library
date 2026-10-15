<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identidad pública del Atelier
            $table->string('username', 30)->nullable()->after('email');
            $table->text('bio')->nullable()->after('avatar');
            $table->boolean('is_public_profile')->default(true)->after('bio');

            $table->index('username');
        });

        // Backfill: genera username único para usuarios existentes a partir
        // del nombre (o del email como fallback), garantizando unicidad.
        $taken = [];
        DB::table('users')->orderBy('id')->each(function ($user) use (&$taken) {
            $base = Str::slug($user->name ?: Str::before($user->email, '@'), '');
            $base = $base !== '' ? Str::limit($base, 24, '') : 'lector';

            $candidate = $base;
            $i = 1;
            while (in_array($candidate, $taken, true)
                || DB::table('users')->where('username', $candidate)->exists()) {
                $candidate = $base . $i;
                $i++;
            }
            $taken[] = $candidate;

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        });

        // Una vez backfilleado, hacemos el username único.
        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropIndex(['username']);
            $table->dropColumn(['username', 'bio', 'is_public_profile']);
        });
    }
};
