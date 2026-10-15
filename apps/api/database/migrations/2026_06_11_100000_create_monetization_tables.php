<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa de monetización CharBook — economía de tres monedas.
 * Ver docs/MONETIZACION-DISENO.md. Todo parámetro económico vive en
 * platform_settings (nunca hardcodeado).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== Parámetros de plataforma (editables sin deploy) =====
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->string('description', 300)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ===== Billetera del lector (premium + reward) =====
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('premium_balance')->default(0);
            $table->bigInteger('reward_balance')->default(0);
            $table->timestamps();
        });

        // Ledger inmutable del lector. El saldo SIEMPRE es derivable del ledger.
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 10); // premium | reward
            $table->bigInteger('amount');   // + crédito, - débito
            $table->bigInteger('balance_after');
            $table->string('type', 30); // purchase|unlock|ad_reward|daily_login|streak|book_complete|achievement|event|refund|admin_adjust|fund
            $table->nullableMorphs('reference'); // unlock, ad_reward, revenue_event...
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'currency', 'created_at']);
            $table->index(['user_id', 'type', 'created_at']);
        });

        // ===== Paquetes de monedas comprables (Stripe Checkout) =====
        Schema::create('coin_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('coins');
            $table->unsignedInteger('bonus_coins')->default(0);
            $table->decimal('price_usd', 8, 2);
            $table->string('stripe_price_id', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // ===== Suscripciones (fuente de verdad; users.subscription_tier se sincroniza) =====
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 20); // premium | premium_plus
            $table->string('stripe_subscription_id', 120)->nullable()->unique();
            $table->string('status', 30)->default('active'); // active|past_due|canceled|incomplete
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // ===== Billetera del creador =====
        Schema::create('creator_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('pending_coins')->default(0);   // en retención
            $table->bigInteger('available_coins')->default(0); // retirables
            $table->bigInteger('lifetime_coins')->default(0);
            $table->boolean('payouts_enabled')->default(false); // KYC Stripe ok
            $table->timestamps();
        });

        // Ledger inmutable del creador (Creator Coins).
        Schema::create('creator_coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('amount'); // + ganancia, - clawback/payout
            $table->string('status', 20)->default('pending'); // pending|available|reversed|paid_out
            $table->string('source', 30); // unlock|creator_fund|adjust|clawback|payout
            $table->timestamp('available_at')->nullable(); // fin de retención
            $table->foreignId('revenue_event_id')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status']);
            $table->index(['status', 'available_at']);
        });

        // ===== Desbloqueos permanentes (nunca se paga dos veces) =====
        Schema::create('unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('coins_premium_spent')->default(0);
            $table->unsignedInteger('coins_reward_spent')->default(0);
            $table->string('source', 20)->default('single'); // single|pack|early_access|admin
            $table->unsignedInteger('pack_size')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'chapter_id']);
            $table->index(['user_id', 'book_id']);
        });

        // ===== Eventos de lectura real (antifraude + Creator Fund) =====
        Schema::create('reading_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('milestone', 20); // chapter_open|chapter_25|chapter_50|chapter_75|chapter_complete
            $table->unsignedInteger('seconds_accumulated')->default(0);
            $table->unsignedTinyInteger('scroll_pct')->default(0);
            $table->boolean('is_valid')->default(false);
            $table->date('day');
            $table->timestamps();

            $table->unique(['user_id', 'chapter_id', 'milestone', 'day']);
            $table->index(['day', 'is_valid']);
            $table->index(['book_id', 'day']);
        });

        // ===== Anuncios recompensados (SSV server-side) =====
        Schema::create('ad_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('admob'); // admob|adsense|ad_manager|sandbox
            $table->string('placement', 40)->default('reward_screen'); // catalog|profile|reward_screen|between_chapters
            $table->string('nonce', 80)->unique();
            $table->string('status', 20)->default('pending'); // pending|confirmed|rejected|expired
            $table->unsignedInteger('coins')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->jsonb('ssv_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
        });

        // ===== Contabilidad de plataforma =====
        Schema::create('revenue_events', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40); // subscription_premium|subscription_plus|coin_purchase|unlock_commission|advertising|creator_fund|other
            $table->decimal('gross_usd', 10, 2)->default(0);
            $table->decimal('platform_usd', 10, 2)->default(0);
            $table->decimal('creator_usd', 10, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stripe_event_id', 120)->nullable()->unique();
            $table->string('stripe_object_id', 120)->nullable(); // session/invoice/charge id
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index(['creator_id', 'created_at']);
        });

        // Agregado diario por creador (gráficas del panel sin sumar el ledger)
        Schema::create('creator_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->string('source', 30); // unlock|creator_fund|adjust
            $table->bigInteger('coins')->default(0);
            $table->decimal('usd_value', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['creator_id', 'day', 'source']);
        });

        // ===== Retiros (Stripe Connect) =====
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('coins_converted');
            $table->decimal('amount_usd', 10, 2);
            $table->decimal('fee_usd', 10, 2)->default(0);
            $table->string('status', 20)->default('requested'); // requested|processing|paid|failed|rejected
            $table->string('stripe_transfer_id', 120)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // ===== Creator Fund mensual =====
        Schema::create('creator_fund_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique(); // YYYY-MM
            $table->decimal('pool_usd', 12, 2);
            $table->bigInteger('total_points')->default(0);
            $table->string('status', 20)->default('computed'); // computed|credited
            $table->jsonb('breakdown')->nullable(); // [{creator_id, points, usd, coins}]
            $table->timestamps();
        });

        // ===== Campos nuevos en tablas existentes =====
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id', 120)->nullable()->index();
            $table->string('stripe_account_id', 120)->nullable()->index(); // Connect Express
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->unsignedInteger('coin_price')->default(0); // 0 = gratis
            $table->timestamp('early_access_until')->nullable(); // antes de esto: solo Plus / premium coins
        });

        Schema::table('books', function (Blueprint $table) {
            $table->jsonb('monetization')->nullable(); // {free_chapters, packs[], pack_discount_pct{}}
            $table->boolean('in_unlimited_catalog')->default(false); // participa en Premium Plus / fund
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['monetization', 'in_unlimited_catalog']);
        });
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn(['coin_price', 'early_access_until']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'stripe_account_id']);
        });

        Schema::dropIfExists('creator_fund_distributions');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('creator_earnings');
        Schema::dropIfExists('revenue_events');
        Schema::dropIfExists('ad_rewards');
        Schema::dropIfExists('reading_events');
        Schema::dropIfExists('unlocks');
        Schema::dropIfExists('creator_coin_transactions');
        Schema::dropIfExists('creator_wallets');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('coin_packages');
        Schema::dropIfExists('coin_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('platform_settings');
    }
};
