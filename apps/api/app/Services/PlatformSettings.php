<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros económicos de la plataforma — editables sin deploy.
 * Nunca hardcodear porcentajes/precios: siempre leer de aquí.
 * Caché 60s (Redis) para no golpear la BD en cada request.
 */
class PlatformSettings
{
    public const DEFAULTS = [
        // Reparto de ingresos (NO 60/40 fijo; por nivel de creador)
        'revenue_split' => ['default' => 70, 'by_creator_level' => ['basic' => 60, 'verified' => 70, 'featured' => 75]],

        // Valor económico de las monedas
        'coins.premium_usd_per_coin' => 0.0099, // tasa media de referencia para splits
        'creator.coin_to_usd' => 0.01,          // conversión Creator Coin → USD en retiros

        // Suscripciones (price ids de Stripe; cambiables sin deploy)
        'subs.premium_price_id' => null,
        'subs.premium_plus_price_id' => null,
        'subs.premium_price_usd' => 4.99,
        'subs.premium_plus_price_usd' => 9.99,
        'subs.premium_unlock_discount_pct' => 10, // descuento de Premium en desbloqueos

        // Anti-inflación de CharCoins Reward
        'rewards.daily_cap' => 10,
        'rewards.ad_daily_cap' => 5,
        'rewards.weekly_cap' => 50,
        'rewards.max_pct_per_unlock' => 50, // % máx de un desbloqueo pagable con reward
        'rewards.login_daily' => 1,
        'rewards.book_complete' => 2,
        'rewards.streak_7' => 3,
        'rewards.per_ad' => 1,

        // Lectura válida (antifraude)
        'reading.min_seconds_per_chapter' => 45,
        'reading.daily_valid_chapters' => 60,

        // Retiros (Stripe Connect)
        'payouts.min_usd' => 50,
        'payouts.fee_pct' => 0,
        'payouts.max_per_week' => 1,
        'payouts.hold_days' => 7, // retención antifraude/chargebacks

        // Creator Fund
        'fund.premium_plus_pct' => 50,

        // Anuncios
        'ads.enabled' => true,
        'ads.dev_autoconfirm' => true, // SOLO dev: confirma sin SSV real

        // Precios de capítulo (economía sostenible)
        'pricing.chapter_max_price' => 50,        // tope duro por capítulo
        'pricing.chapter_recommended_min' => 5,   // sugerencia UI
        'pricing.chapter_recommended_max' => 15,  // sugerencia UI

        // "Esperar para desbloquear" gratis (alternativa a pagar con monedas).
        // Recomendado: 48 h (2 días) — suficiente para incentivar el plan/monedas
        // sin frustrar al lector. Configurable global y por obra (monetization).
        'unlocks.wait_enabled' => true,
        'unlocks.wait_hours' => 48,
    ];

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $fallback ?? (self::DEFAULTS[$key] ?? null);
    }

    /** Todos los settings (defaults + overrides de BD). */
    public static function all(): array
    {
        $db = Cache::remember('platform_settings', 60, function () {
            return DB::table('platform_settings')
                ->pluck('value', 'key')
                ->map(fn ($v) => json_decode($v, true))
                ->all();
        });

        return array_merge(self::DEFAULTS, $db);
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value),
                'updated_by' => $userId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        Cache::forget('platform_settings');
    }

    /** % que recibe el creador según su nivel (basic|verified|featured|none). */
    public static function creatorSharePct(?string $level): int
    {
        $split = self::get('revenue_split');
        return (int) ($split['by_creator_level'][$level] ?? $split['default'] ?? 70);
    }
}
