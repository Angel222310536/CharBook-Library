<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdReward;
use App\Models\Book;
use App\Models\CoinPackage;
use App\Models\CoinTransaction;
use App\Models\Unlock;
use App\Models\Wallet;
use App\Services\AdRewardService;
use App\Services\PlatformSettings;
use App\Services\ReadingEventService;
use App\Services\UnlockService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints del lector: billetera, paywall, desbloqueos, recompensas, lectura.
 * Todo se valida server-side; el cliente solo expresa intenciones.
 */
class MonetizationController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly UnlockService $unlocks,
        private readonly ReadingEventService $readingEvents,
        private readonly AdRewardService $adRewards,
    ) {}

    /** GET /wallet — saldos + plan + límites del día. */
    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'premium_balance' => (int) $wallet->premium_balance,
            'reward_balance' => (int) $wallet->reward_balance,
            'plan' => $this->unlocks->activePlan($user),
            'reward_limits' => [
                'daily_cap' => (int) PlatformSettings::get('rewards.daily_cap'),
                'earned_today' => $this->wallets->rewardEarnedToday($user->id),
                'ad_daily_cap' => (int) PlatformSettings::get('rewards.ad_daily_cap'),
                'ads_today' => AdReward::where('user_id', $user->id)->where('status', 'confirmed')->whereDate('confirmed_at', today())->count(),
                'max_pct_per_unlock' => (int) PlatformSettings::get('rewards.max_pct_per_unlock'),
            ],
        ]);
    }

    /** GET /wallet/transactions */
    public function transactions(Request $request): JsonResponse
    {
        $txs = CoinTransaction::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(30);

        return response()->json($txs);
    }

    /** GET /coin-packages (público) */
    public function packages(): JsonResponse
    {
        return response()->json([
            'data' => CoinPackage::where('is_active', true)->orderBy('sort')->get(),
        ]);
    }

    /** GET /books/{slug}/access — mapa de paywall por capítulo para el lector web. */
    public function bookAccess(Request $request, Book $book): JsonResponse
    {
        $user = $request->user('sanctum');
        $chapters = $book->chapters()->where('is_published', true)->orderBy('number')->get();

        $map = $chapters->map(function ($c) use ($user, $book) {
            $access = $this->unlocks->chapterAccess($user, $book, $c);
            return [
                'chapter_id' => $c->id,
                'number' => $c->number,
                'coin_price' => (int) $c->coin_price,
                'early_access_until' => $c->early_access_until?->toIso8601String(),
            ] + $access;
        });

        return response()->json([
            'data' => $map,
            'monetization' => [
                'free_chapters' => (int) data_get($book->monetization, 'free_chapters', 0),
                'packs' => data_get($book->monetization, 'packs', [3, 5, 10]),
                'pack_discount_pct' => data_get($book->monetization, 'pack_discount_pct', new \stdClass),
                'in_unlimited_catalog' => (bool) $book->in_unlimited_catalog,
            ],
            'plan' => $this->unlocks->activePlan($user),
        ]);
    }

    /** POST /chapters/{chapter}/unlock { use_reward?, idempotency_key } */
    public function unlockChapter(Request $request, \App\Models\Chapter $chapter): JsonResponse
    {
        $data = $request->validate([
            'use_reward' => 'sometimes|integer|min:0',
            'idempotency_key' => 'required|string|max:80',
        ]);

        $book = $chapter->book;
        $access = $this->unlocks->chapterAccess($request->user(), $book, $chapter);
        if ($access['access']) {
            return response()->json(['message' => 'Ya tienes acceso a este capítulo.', 'access' => $access]);
        }

        $result = $this->unlocks->unlock(
            $request->user(),
            $book,
            collect([$chapter]),
            'single',
            null,
            (int) ($data['use_reward'] ?? 0),
            $data['idempotency_key'],
        );

        return response()->json($result, 201);
    }

    /**
     * POST /chapters/{chapter}/wait-unlock — reserva el capítulo para que se
     * desbloquee gratis tras el tiempo de espera. Solo capítulos de pago
     * normales (no acceso anticipado) y solo lectores sin plan.
     */
    public function startWaitUnlock(Request $request, \App\Models\Chapter $chapter): JsonResponse
    {
        $user = $request->user();
        $book = $chapter->book;

        $access = $this->unlocks->chapterAccess($user, $book, $chapter);
        if ($access['access']) {
            return response()->json(['message' => 'Ya tienes acceso a este capítulo.', 'access' => $access]);
        }
        if (! ($access['wait_available'] ?? false)) {
            throw ValidationException::withMessages([
                'wait' => ['La espera gratuita no está disponible para este capítulo.'],
            ]);
        }

        $wait = $this->unlocks->startWait($user, $book, $chapter);

        return response()->json([
            'unlocks_at' => $wait->unlocks_at->toIso8601String(),
            'wait_hours' => $this->unlocks->waitConfig($book)['hours'],
        ], 201);
    }

    /** POST /books/{slug}/unlock-pack { size, from_chapter?, use_reward?, idempotency_key } */
    public function unlockPack(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'size' => 'required|integer|min:2|max:50',
            'from_chapter' => 'sometimes|integer|min:1',
            'use_reward' => 'sometimes|integer|min:0',
            'idempotency_key' => 'required|string|max:80',
        ]);

        $allowedPacks = data_get($book->monetization, 'packs', [3, 5, 10]);
        if (! in_array($data['size'], $allowedPacks)) {
            throw ValidationException::withMessages(['size' => ['Ese pack no está disponible para esta obra.']]);
        }

        $user = $request->user();

        // Próximos N capítulos BLOQUEADOS desde from_chapter (o desde el principio)
        $chapters = $book->chapters()
            ->where('is_published', true)
            ->when(isset($data['from_chapter']), fn ($q) => $q->where('number', '>=', $data['from_chapter']))
            ->orderBy('number')
            ->get()
            ->filter(fn ($c) => ! $this->unlocks->chapterAccess($user, $book, $c)['access'])
            ->take($data['size'])
            ->values();

        if ($chapters->isEmpty()) {
            throw ValidationException::withMessages(['size' => ['No hay capítulos bloqueados por delante.']]);
        }

        $result = $this->unlocks->unlock(
            $user,
            $book,
            $chapters,
            'pack',
            $data['size'],
            (int) ($data['use_reward'] ?? 0),
            $data['idempotency_key'],
        );

        return response()->json($result, 201);
    }

    /** GET /me/unlocks — historial permanente. */
    public function myUnlocks(Request $request): JsonResponse
    {
        $unlocks = Unlock::where('user_id', $request->user()->id)
            ->with(['book:id,title,slug', 'chapter:id,number,title'])
            ->latest()
            ->paginate(30);

        return response()->json($unlocks);
    }

    /** POST /rewards/daily-login — 1 vez al día, bajo caps. */
    public function dailyLogin(Request $request): JsonResponse
    {
        $user = $request->user();
        $granted = $this->wallets->creditRewardCapped(
            $user->id,
            (int) PlatformSettings::get('rewards.login_daily'),
            'daily_login',
            'daily_login:' . $user->id . ':' . today()->toDateString(),
        );

        return response()->json(['granted' => $granted]);
    }

    /** POST /reading-events { book_id, chapter_id?, milestone, seconds, scroll_pct } */
    public function readingEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_id' => 'required|integer|exists:books,id',
            'chapter_id' => 'nullable|integer|exists:chapters,id',
            'milestone' => 'required|string|in:' . implode(',', ReadingEventService::MILESTONES),
            'seconds' => 'required|integer|min:0|max:36000',
            'scroll_pct' => 'required|integer|min:0|max:100',
        ]);

        $event = $this->readingEvents->record(
            $request->user(),
            $data['book_id'],
            $data['chapter_id'] ?? null,
            $data['milestone'],
            $data['seconds'],
            $data['scroll_pct'],
        );

        return response()->json(['data' => ['milestone' => $event->milestone, 'is_valid' => $event->is_valid]]);
    }

    /** POST /ads/session { placement } — crea sesión de anuncio recompensado. */
    public function adSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placement' => 'required|string|in:' . implode(',', AdRewardService::PLACEMENTS),
        ]);

        $session = $this->adRewards->createSession($request->user(), $data['placement']);

        // Modo dev: confirmar sin SSV real del proveedor
        if (PlatformSettings::get('ads.dev_autoconfirm')) {
            $result = $this->adRewards->confirm($session->nonce, ['dev' => true]);
            return response()->json(['nonce' => $session->nonce, 'dev_confirmed' => true] + $result, 201);
        }

        return response()->json(['nonce' => $session->nonce, 'coins' => $session->coins], 201);
    }

    /**
     * GET /ads/ssv-callback — callback servidor-a-servidor del proveedor (AdMob SSV).
     * En producción: verificar la firma del proveedor ANTES de confirmar.
     */
    public function adSsvCallback(Request $request): JsonResponse
    {
        $nonce = (string) $request->query('custom_data', $request->query('nonce', ''));
        if (! $nonce) {
            return response()->json(['ok' => false], 400);
        }

        try {
            $result = $this->adRewards->confirm($nonce, $request->query());
            return response()->json(['ok' => true] + $result);
        } catch (ValidationException) {
            return response()->json(['ok' => false], 422);
        }
    }
}
