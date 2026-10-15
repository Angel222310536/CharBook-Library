<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoinPackage;
use App\Models\CreatorWallet;
use App\Models\Payout;
use App\Models\RevenueEvent;
use App\Services\CreatorLedgerService;
use App\Services\PlatformSettings;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel admin de monetización: settings sin deploy, paquetes,
 * métricas financieras, retiros y Creator Fund.
 */
class MonetizationAdminController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly CreatorLedgerService $ledger,
    ) {}

    /** GET /admin/monetization/settings — defaults + overrides. */
    public function settings(): JsonResponse
    {
        return response()->json([
            'data' => PlatformSettings::all(),
            'defaults' => PlatformSettings::DEFAULTS,
        ]);
    }

    /** PUT /admin/monetization/settings { settings: {key: value, ...} } */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => 'required|array']);

        foreach ($data['settings'] as $key => $value) {
            // Solo claves conocidas: evita basura en la tabla
            if (! array_key_exists($key, PlatformSettings::DEFAULTS)) {
                continue;
            }
            PlatformSettings::set($key, $value, $request->user()->id);
        }

        return response()->json(['data' => PlatformSettings::all(), 'message' => 'Parámetros actualizados.']);
    }

    /** CRUD de paquetes de monedas */
    public function packages(): JsonResponse
    {
        return response()->json(['data' => CoinPackage::orderBy('sort')->get()]);
    }

    public function storePackage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'coins' => 'required|integer|min:1',
            'bonus_coins' => 'sometimes|integer|min:0',
            'price_usd' => 'required|numeric|min:0.5',
            'stripe_price_id' => 'nullable|string|max:120',
            'is_active' => 'sometimes|boolean',
            'sort' => 'sometimes|integer',
        ]);

        return response()->json(['data' => CoinPackage::create($data)], 201);
    }

    public function updatePackage(Request $request, CoinPackage $package): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'coins' => 'sometimes|integer|min:1',
            'bonus_coins' => 'sometimes|integer|min:0',
            'price_usd' => 'sometimes|numeric|min:0.5',
            'stripe_price_id' => 'nullable|string|max:120',
            'is_active' => 'sometimes|boolean',
            'sort' => 'sometimes|integer',
        ]);
        $package->update($data);

        return response()->json(['data' => $package]);
    }

    public function destroyPackage(CoinPackage $package): JsonResponse
    {
        $package->update(['is_active' => false]); // soft: histórico de compras intacto

        return response()->json(['message' => 'Paquete desactivado.']);
    }

    /** GET /admin/finance/overview — métricas financieras globales. */
    public function financeOverview(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $byCategory = RevenueEvent::whereBetween('created_at', [$from, $to])
            ->selectRaw("category, sum(gross_usd) as gross, sum(platform_usd) as platform, sum(creator_usd) as creators, count(*) as events")
            ->groupBy('category')
            ->get();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'by_category' => $byCategory,
            'totals' => [
                'gross_usd' => round((float) $byCategory->sum('gross'), 2),
                'platform_usd' => round((float) $byCategory->sum('platform'), 2),
                'creators_usd' => round((float) $byCategory->sum('creators'), 2),
            ],
            'creators' => [
                'pending_coins' => (int) CreatorWallet::sum('pending_coins'),
                'available_coins' => (int) CreatorWallet::sum('available_coins'),
                'payouts_paid_usd' => round((float) Payout::where('status', 'paid')->sum('amount_usd'), 2),
                'payouts_pending' => Payout::whereIn('status', ['requested', 'processing'])->count(),
            ],
        ]);
    }

    /** GET /admin/payouts?status= */
    public function payoutsIndex(Request $request): JsonResponse
    {
        $q = Payout::with('creator:id,name,username,email')
            ->when($request->query('status'), fn ($qq, $s) => $qq->where('status', $s))
            ->latest();

        return response()->json($q->paginate(30));
    }

    /** PATCH /admin/payouts/{payout} { action: retry|reject } */
    public function payoutAction(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:retry,reject']);

        if ($data['action'] === 'retry' && in_array($payout->status, ['failed', 'requested'], true)) {
            if ($payout->status === 'failed') {
                // El saldo fue repuesto al fallar: volver a debitar
                $this->ledger->debitForPayout($payout->creator_id, $payout->coins_converted, $payout->id);
            }
            $payout = $this->stripe->processPayout($payout);
        }

        if ($data['action'] === 'reject' && in_array($payout->status, ['requested', 'failed'], true)) {
            if ($payout->status === 'requested') {
                // Reponer saldo
                CreatorWallet::where('user_id', $payout->creator_id)
                    ->increment('available_coins', $payout->coins_converted);
            }
            $payout->update(['status' => 'rejected']);
        }

        return response()->json(['data' => $payout->fresh()]);
    }

    /** POST /admin/creator-fund/run { month?: YYYY-MM } */
    public function runCreatorFund(Request $request): JsonResponse
    {
        $month = $request->input('month', now()->subMonth()->format('Y-m'));

        \Illuminate\Support\Facades\Artisan::call('charbook:creator-fund', ['month' => $month]);

        return response()->json([
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()),
        ]);
    }
}
