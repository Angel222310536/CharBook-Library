<?php

namespace App\Services;

use App\Models\AdReward;
use App\Models\RevenueEvent;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Anuncios recompensados — el cliente NUNCA acredita monedas.
 *
 * Flujo: el cliente pide una sesión (nonce) → muestra el anuncio →
 * el proveedor (AdMob SSV / Ad Manager) llama a nuestro callback
 * servidor-a-servidor con el nonce → solo entonces acreditamos,
 * bajo caps anti-inflación. En dev: ads.dev_autoconfirm.
 */
class AdRewardService
{
    public const PLACEMENTS = ['catalog', 'profile', 'reward_screen', 'between_chapters'];

    public function __construct(private readonly WalletService $wallets) {}

    public function createSession(User $user, string $placement, string $provider = 'admob'): AdReward
    {
        if (! PlatformSettings::get('ads.enabled')) {
            throw ValidationException::withMessages(['ads' => ['Los anuncios están desactivados.']]);
        }
        if (! in_array($placement, self::PLACEMENTS, true)) {
            throw ValidationException::withMessages(['placement' => ['Ubicación de anuncio inválida.']]);
        }

        // Cap específico de anuncios por día
        $adCap = (int) PlatformSettings::get('rewards.ad_daily_cap');
        $confirmedToday = AdReward::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->whereDate('confirmed_at', today())
            ->count();
        if ($confirmedToday >= $adCap) {
            throw ValidationException::withMessages(['ads' => ['Alcanzaste el límite de anuncios de hoy. Vuelve mañana.']]);
        }

        return AdReward::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'placement' => $placement,
            'nonce' => Str::uuid()->toString(),
            'status' => 'pending',
            'coins' => (int) PlatformSettings::get('rewards.per_ad'),
        ]);
    }

    /**
     * Confirmación SSV (server-side verification) del proveedor.
     * En producción se valida la firma criptográfica del proveedor
     * ANTES de llamar aquí (ver StripeWebhook-like verification del proveedor).
     */
    public function confirm(string $nonce, array $ssvPayload = []): array
    {
        $session = AdReward::where('nonce', $nonce)->first();

        if (! $session || $session->status !== 'pending') {
            throw ValidationException::withMessages(['nonce' => ['Sesión de anuncio inválida o ya usada.']]);
        }
        if ($session->created_at->lt(now()->subMinutes(30))) {
            $session->update(['status' => 'expired']);
            throw ValidationException::withMessages(['nonce' => ['Sesión de anuncio expirada.']]);
        }

        $granted = $this->wallets->creditRewardCapped(
            $session->user_id,
            $session->coins,
            'ad_reward',
            "ad:{$session->nonce}",
            ['placement' => $session->placement, 'provider' => $session->provider],
        );

        $session->update([
            'status' => 'confirmed',
            'coins' => $granted,
            'confirmed_at' => now(),
            'ssv_payload' => $ssvPayload ?: null,
        ]);

        // Los ingresos publicitarios pertenecen a la plataforma (no generan Creator Coins)
        RevenueEvent::create([
            'category' => 'advertising',
            'gross_usd' => 0, // el ingreso real llega por liquidación del proveedor; aquí solo trazamos el evento
            'platform_usd' => 0,
            'user_id' => $session->user_id,
            'meta' => ['ad_reward_id' => $session->id, 'placement' => $session->placement],
        ]);

        return ['granted' => $granted, 'capped' => $granted < (int) PlatformSettings::get('rewards.per_ad')];
    }
}
