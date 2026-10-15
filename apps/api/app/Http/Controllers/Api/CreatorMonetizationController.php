<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\CreatorCoinTransaction;
use App\Models\CreatorEarning;
use App\Models\CreatorWallet;
use App\Models\Payout;
use App\Services\CreatorLedgerService;
use App\Services\PlatformSettings;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Panel financiero del creador: saldos, ganancias, Stripe Connect, retiros
 * y configuración del paywall de sus obras.
 */
class CreatorMonetizationController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly CreatorLedgerService $ledger,
    ) {}

    /** GET /me/creator/wallet */
    public function wallet(Request $request): JsonResponse
    {
        $wallet = CreatorWallet::firstOrCreate(['user_id' => $request->user()->id]);
        $coinToUsd = (float) PlatformSettings::get('creator.coin_to_usd');

        return response()->json([
            'pending_coins' => (int) $wallet->pending_coins,
            'available_coins' => (int) $wallet->available_coins,
            'lifetime_coins' => (int) $wallet->lifetime_coins,
            'pending_usd' => round($wallet->pending_coins * $coinToUsd, 2),
            'available_usd' => round($wallet->available_coins * $coinToUsd, 2),
            'payouts_enabled' => (bool) $wallet->payouts_enabled,
            'coin_to_usd' => $coinToUsd,
            'payout_rules' => [
                'min_usd' => PlatformSettings::get('payouts.min_usd'),
                'fee_pct' => PlatformSettings::get('payouts.fee_pct'),
                'max_per_week' => PlatformSettings::get('payouts.max_per_week'),
                'hold_days' => PlatformSettings::get('payouts.hold_days'),
            ],
            'revenue_share_pct' => PlatformSettings::creatorSharePct($request->user()->creator_level),

            // ===== Lenguaje humano para el panel (sin porcentajes) =====
            'creator_level' => $request->user()->creator_level, // none|basic|verified|featured
            'creator_level_label' => match ($request->user()->creator_level) {
                'featured' => 'Creador Destacado',
                'verified' => 'Creador Verificado',
                'basic' => 'Creador Básico',
                default => 'Creador',
            },
            // "Si un lector gasta X coins en tu capítulo, tú recibes Y Creator Coins".
            // Calculado server-side con la misma fórmula del UnlockService.
            'earnings_examples' => collect([5, 10, 20])->map(function ($coins) use ($request) {
                $sharePct = PlatformSettings::creatorSharePct($request->user()->creator_level);
                $usdPerCoin = (float) PlatformSettings::get('coins.premium_usd_per_coin');
                $coinToUsd = (float) PlatformSettings::get('creator.coin_to_usd');
                $creatorCoins = (int) floor(round($coins * $usdPerCoin * $sharePct / 100, 4) / $coinToUsd);
                return ['reader_spends' => $coins, 'you_earn' => $creatorCoins];
            })->values(),
            'pricing_rules' => [
                'chapter_max_price' => PlatformSettings::get('pricing.chapter_max_price', 50),
                'recommended_min' => PlatformSettings::get('pricing.chapter_recommended_min', 5),
                'recommended_max' => PlatformSettings::get('pricing.chapter_recommended_max', 15),
            ],
        ]);
    }

    /** GET /me/creator/earnings?days=30 — agregado diario para gráficas. */
    public function earnings(Request $request): JsonResponse
    {
        $days = min(365, (int) $request->query('days', 30));

        $rows = CreatorEarning::where('creator_id', $request->user()->id)
            ->where('day', '>=', today()->subDays($days))
            ->orderBy('day')
            ->get(['day', 'source', 'coins', 'usd_value']);

        return response()->json([
            'data' => $rows,
            'totals' => [
                'coins' => (int) $rows->sum('coins'),
                'usd' => round((float) $rows->sum('usd_value'), 2),
            ],
        ]);
    }

    /** GET /me/creator/transactions — ledger completo. */
    public function transactions(Request $request): JsonResponse
    {
        return response()->json(
            CreatorCoinTransaction::where('creator_id', $request->user()->id)
                ->latest()
                ->paginate(30)
        );
    }

    /** POST /me/creator/connect — link de onboarding KYC de Stripe Connect Express. */
    public function connect(Request $request): JsonResponse
    {
        $front = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $url = $this->stripe->connectOnboardingLink(
            $request->user(),
            $front . '/studio/ingresos?connect=done',
            $front . '/studio/ingresos?connect=retry',
        );

        return response()->json(['url' => $url]);
    }

    /** GET /me/creator/connect/status */
    public function connectStatus(Request $request): JsonResponse
    {
        return response()->json($this->stripe->connectStatus($request->user()));
    }

    /** POST /me/creator/payouts — solicitar retiro (reglas configurables). */
    public function requestPayout(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = CreatorWallet::firstOrCreate(['user_id' => $user->id]);

        if (! $wallet->payouts_enabled) {
            throw ValidationException::withMessages(['payout' => ['Completa la verificación de Stripe Connect antes de retirar.']]);
        }

        // Frecuencia máxima configurable
        $maxPerWeek = (int) PlatformSettings::get('payouts.max_per_week');
        $recent = Payout::where('creator_id', $user->id)
            ->whereNotIn('status', ['failed', 'rejected'])
            ->where('created_at', '>=', now()->subWeek())
            ->count();
        if ($recent >= $maxPerWeek) {
            throw ValidationException::withMessages(['payout' => ["Máximo {$maxPerWeek} retiro(s) por semana."]]);
        }

        $coinToUsd = (float) PlatformSettings::get('creator.coin_to_usd');
        $minUsd = (float) PlatformSettings::get('payouts.min_usd');
        $feePct = (float) PlatformSettings::get('payouts.fee_pct');

        $coins = (int) $wallet->available_coins;
        $amountUsd = round($coins * $coinToUsd, 2);

        if ($amountUsd < $minUsd) {
            throw ValidationException::withMessages(['payout' => ["El retiro mínimo es {$minUsd} USD. Tu saldo disponible es {$amountUsd} USD."]]);
        }

        $payout = Payout::create([
            'creator_id' => $user->id,
            'coins_converted' => $coins,
            'amount_usd' => $amountUsd,
            'fee_usd' => round($amountUsd * $feePct / 100, 2),
            'status' => 'requested',
        ]);

        // Debitar el saldo disponible YA (si el transfer falla se repone)
        $this->ledger->debitForPayout($user->id, $coins, $payout->id);

        // Procesamiento automático vía Stripe Connect (sin pagos manuales)
        try {
            $payout = $this->stripe->processPayout($payout);
        } catch (\Throwable) {
            // queda en failed y el saldo fue repuesto por processPayout
        }

        return response()->json(['data' => $payout], 201);
    }

    /** GET /me/creator/payouts */
    public function payouts(Request $request): JsonResponse
    {
        return response()->json(
            Payout::where('creator_id', $request->user()->id)->latest()->paginate(20)
        );
    }

    /**
     * PATCH /me/works/{work}/monetization
     * { free_chapters, packs[], pack_discount_pct{}, chapters: [{id, coin_price, early_access_until}] }
     *
     * Reglas de economía sostenible (UX 2026-06-12):
     *  - Packs FIJOS: solo 3 (pequeño), 5 (mediano), 10 (grande). El creador
     *    los activa/desactiva y fija el descuento; nada de tamaños arbitrarios.
     *  - Precio por capítulo acotado por settings (`pricing.chapter_max_price`,
     *    default 50) para evitar capítulos de 100/500 coins que rompen la economía.
     *  - `in_unlimited_catalog` NO lo decide el creador: lo cura la plataforma
     *    (admin). Si llega en el payload, se ignora (compat sin romper clientes).
     */
    public function updateWorkMonetization(Request $request, Book $work): JsonResponse
    {
        abort_unless($work->creator_id === $request->user()->id, 403, 'No es tu obra.');

        $maxPrice = (int) PlatformSettings::get('pricing.chapter_max_price', 50);

        $data = $request->validate([
            'free_chapters' => 'sometimes|integer|min:0|max:1000',
            'packs' => 'sometimes|array|max:3',
            'packs.*' => 'integer|in:3,5,10',
            'pack_discount_pct' => 'sometimes|array',
            'pack_discount_pct.*' => 'integer|min:0|max:90',
            'chapters' => 'sometimes|array',
            'chapters.*.id' => 'required|integer',
            'chapters.*.coin_price' => "required|integer|min:0|max:{$maxPrice}",
            'chapters.*.early_access_until' => 'nullable|date',
        ], [
            'packs.*.in' => 'Los packs disponibles son fijos: 3, 5 o 10 capítulos.',
            'chapters.*.coin_price.max' => "El precio máximo por capítulo es {$maxPrice} CharCoins.",
        ]);

        $monetization = (array) ($work->monetization ?? []);
        foreach (['free_chapters', 'packs', 'pack_discount_pct'] as $key) {
            if (array_key_exists($key, $data)) {
                $monetization[$key] = $data[$key];
            }
        }

        // in_unlimited_catalog se conserva tal cual: solo admin/curaduría lo cambia.
        $work->update(['monetization' => $monetization]);

        if (! empty($data['chapters'])) {
            foreach ($data['chapters'] as $ch) {
                $work->chapters()->where('id', $ch['id'])->update([
                    'coin_price' => $ch['coin_price'],
                    'early_access_until' => $ch['early_access_until'] ?? null,
                ]);
            }
        }

        return response()->json(['message' => 'Monetización actualizada.', 'monetization' => $monetization]);
    }
}
