<?php

namespace App\Services;

use App\Models\User;

/**
 * Capa única de "entitlements" (qué puede usar cada plan).
 *
 * Fuente de verdad server-side para Premium / Premium Plus. NO confiar en el
 * cliente: los endpoints sensibles validan con `Entitlements::can()` o el
 * middleware `entitlement:<feature>`.
 *
 * Planes (rango): free(0) < premium(1) < premium_plus(2).
 */
class Entitlements
{
    public const TIERS = ['free' => 0, 'premium' => 1, 'premium_plus' => 2];

    /** feature => tier mínimo requerido. */
    public const FEATURES = [
        // Premium
        'read_no_ads' => 'premium',
        'read_no_locks' => 'premium',          // acceso total a capítulos de pago
        'smart_recommendations' => 'premium',
        'premium_themes' => 'premium',
        'extra_fonts' => 'premium',
        // Premium Plus
        'ai_audiobooks' => 'premium_plus',
        'ai_voices' => 'premium_plus',
        'advanced_profile' => 'premium_plus',
        'early_access' => 'premium_plus',
        'plus_badge' => 'premium_plus',
        'creator_support' => 'premium_plus',
        'book_summaries' => 'premium_plus',
        'chapter_summaries' => 'premium_plus',
        'priority_access' => 'premium_plus',
    ];

    /**
     * Funciones cuya UI/permiso ya existen pero el motor real todavía no
     * (placeholder). Un usuario CON el plan ve "coming_soon"; uno SIN el plan
     * ve el paywall correspondiente.
     */
    public const COMING_SOON = ['ai_audiobooks', 'book_summaries', 'chapter_summaries'];

    /** Plan efectivo del usuario: free | premium | premium_plus. */
    public static function plan(?User $user): string
    {
        return app(UnlockService::class)->activePlan($user) ?? 'free';
    }

    public static function rank(string $tier): int
    {
        return self::TIERS[$tier] ?? 0;
    }

    public static function isPremium(?User $user): bool
    {
        return self::rank(self::plan($user)) >= self::rank('premium');
    }

    public static function isPremiumPlus(?User $user): bool
    {
        return self::rank(self::plan($user)) >= self::rank('premium_plus');
    }

    /** ¿El usuario puede usar la función? */
    public static function can(?User $user, string $feature): bool
    {
        $required = self::FEATURES[$feature] ?? 'premium_plus';

        return self::rank(self::plan($user)) >= self::rank($required);
    }

    /**
     * Estado de UI de una función para este usuario:
     * available | requires_premium | requires_premium_plus | coming_soon
     */
    public static function state(?User $user, string $feature): string
    {
        if (! self::can($user, $feature)) {
            $required = self::FEATURES[$feature] ?? 'premium_plus';

            return $required === 'premium_plus' ? 'requires_premium_plus' : 'requires_premium';
        }

        return in_array($feature, self::COMING_SOON, true) ? 'coming_soon' : 'available';
    }

    /** Mapa completo de entitlements para el cliente. */
    public static function map(?User $user): array
    {
        $features = [];
        foreach (array_keys(self::FEATURES) as $f) {
            $features[$f] = [
                'can' => self::can($user, $f),
                'state' => self::state($user, $f),
                'requires' => self::FEATURES[$f],
            ];
        }

        return [
            'plan' => self::plan($user),
            'is_premium' => self::isPremium($user),
            'is_premium_plus' => self::isPremiumPlus($user),
            'features' => $features,
        ];
    }
}
