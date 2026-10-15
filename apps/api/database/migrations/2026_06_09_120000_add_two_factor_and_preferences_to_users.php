<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ===== 2FA =====
            $table->boolean('two_factor_enabled')->default(false)->after('password');
            $table->jsonb('two_factor_methods')->nullable()->after('two_factor_enabled');
            // ['totp','email','passkey']
            $table->text('two_factor_secret')->nullable()->after('two_factor_methods');
            // TOTP secret (encriptado por cast)
            $table->jsonb('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // ===== Preferencias generales =====
            $table->jsonb('preferences')->nullable()->after('two_factor_confirmed_at');
            // { theme: 'light'|'dark'|'system', email_frequency, content_filter, autoplay,... }

            // ===== Suscripción (mock) =====
            $table->string('subscription_tier', 30)->default('free')->after('preferences');
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_enabled', 'two_factor_methods', 'two_factor_secret',
                'two_factor_recovery_codes', 'two_factor_confirmed_at',
                'preferences', 'subscription_tier', 'subscription_ends_at',
            ]);
        });
    }
};
