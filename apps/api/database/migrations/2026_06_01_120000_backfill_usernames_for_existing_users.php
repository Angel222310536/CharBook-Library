<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: users que se registraron antes del fix de OAuth (Google) quedaron
 * con username = null y por eso los links a /u/{username} no funcionan.
 * Esta migración les asigna un username único.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::whereNull('username')->orWhere('username', '')
            ->get()
            ->each(function (User $u) {
                $base = $u->email
                    ? explode('@', $u->email)[0]
                    : preg_replace('/\s+/', '', $u->name ?? 'user');

                $u->forceFill([
                    'username' => User::generateUniqueUsername($base ?: 'user'),
                ])->save();
            });
    }

    public function down(): void
    {
        // No reversible — el backfill no se deshace.
    }
};
