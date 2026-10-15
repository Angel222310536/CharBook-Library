<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterWaitUnlock;
use App\Models\RevenueEvent;
use App\Models\Subscription;
use App\Models\Unlock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paywall + desbloqueos.
 *
 * - El acceso se decide SIEMPRE server-side (free / suscripción / unlock previo).
 * - Un capítulo desbloqueado lo es para siempre (unique user+chapter).
 * - Packs ("próximos N") con descuento configurable por creador.
 * - Cada gasto de monedas Premium genera reparto al creador (Creator Coins
 *   pendientes con retención) + revenue_event de comisión para plataforma.
 * - Las monedas Reward NUNCA pagan acceso anticipado ni generan Creator Coins.
 */
class UnlockService
{
    /** Cachés por-request: evitan repetir queries al mapear todo un libro. */
    private array $gateCache = [];
    private array $unlockedCache = [];
    private array $waitCache = [];

    public function __construct(
        private readonly WalletService $wallets,
        private readonly CreatorLedgerService $creatorLedger,
    ) {}

    /** Plan activo del usuario: premium_plus | premium | null. */
    public function activePlan(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $plans = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>', now()))
            ->pluck('plan');

        if ($plans->contains('premium_plus')) {
            return 'premium_plus';
        }
        if ($plans->contains('premium')) {
            return 'premium';
        }

        if (in_array($user->subscription_tier, ['premium_plus', 'premium'], true)) {
            return $user->subscription_tier;
        }

        return null;
    }

    /**
     * Estado de acceso de un capítulo (lectura SECUENCIAL).
     *
     * Reglas:
     * - El acceso explícito (suscripción / desbloqueo pagado / espera cumplida)
     *   siempre se respeta.
     * - Si hay un capítulo de pago ANTERIOR sin desbloquear (la "puerta"), este
     *   capítulo queda bloqueado aunque sea gratis: hay que pagar la puerta primero.
     *   Al pagarla, los gratis siguientes se abren solos hasta la próxima puerta.
     *
     * reason: free | unlocked | subscription | wait | paywall | early_access | locked_sequential
     */
    public function chapterAccess(?User $user, Book $book, Chapter $chapter): array
    {
        $ind = $this->individualAccess($user, $book, $chapter);

        // El acceso explícito (suscripción, pago, espera cumplida) manda siempre.
        if ($ind['access'] && $ind['reason'] !== 'free') {
            return $ind;
        }

        // Puerta de pago anterior sin desbloquear → bloquea todo lo que venga después.
        $gate = $this->firstLockedGate($user, $book);
        if ($gate && $chapter->number > $gate->number) {
            return [
                'access' => false,
                'reason' => 'locked_sequential',
                'price' => (int) $chapter->coin_price,
                'early_access' => $ind['early_access'] ?? false,
                'wait_available' => false,
                'wait_until' => null,
                'gate_number' => $gate->number,
                'gate_chapter_id' => $gate->id,
                'gate_price' => (int) $gate->coin_price,
            ];
        }

        return $ind;
    }

    /**
     * Acceso "individual" de un capítulo, SIN la regla secuencial (sin puerta).
     * Es la base sobre la que chapterAccess aplica el bloqueo en cadena.
     */
    private function individualAccess(?User $user, Book $book, Chapter $chapter): array
    {
        $price = (int) $chapter->coin_price;
        $early = $chapter->early_access_until && now()->lt($chapter->early_access_until);
        $plan = $this->activePlan($user);

        // Capítulos gratuitos por configuración del libro (los N primeros)
        $freeChapters = (int) data_get($book->monetization, 'free_chapters', 0);
        $isFreeByPosition = $freeChapters > 0 && $chapter->number <= $freeChapters;

        if (($price === 0 || $isFreeByPosition) && ! $early) {
            return ['access' => true, 'reason' => 'free', 'price' => 0, 'early_access' => false];
        }

        if ($user && $this->unlockedIds($user, $book)->has($chapter->id)) {
            return ['access' => true, 'reason' => 'unlocked', 'price' => $price, 'early_access' => $early];
        }

        // "Esperar para desbloquear": si reservó y ya pasó el tiempo, queda accesible.
        $wait = $user ? $this->waitRows($user, $book)->get($chapter->id) : null;
        if ($wait && now()->gte($wait->unlocks_at)) {
            return ['access' => true, 'reason' => 'wait', 'price' => $price, 'early_access' => $early];
        }

        // Premium Plus: acceso TOTAL, incluido el acceso anticipado.
        if ($plan === 'premium_plus') {
            return ['access' => true, 'reason' => 'subscription', 'price' => $price, 'early_access' => $early];
        }

        // Premium (normal): acceso a TODO lo de pago salvo el acceso anticipado
        // (ese beneficio es exclusivo de Premium Plus). Durante la ventana early,
        // un Premium cae al paywall con reason 'early_access' (igual que un free,
        // pero sin necesidad de monedas: solo esperar a que termine la ventana).
        if ($plan === 'premium' && ! $early) {
            return ['access' => true, 'reason' => 'subscription', 'price' => $price, 'early_access' => false];
        }

        $waitCfg = $this->waitConfig($book);
        $waitAvailable = $waitCfg['enabled'] && ! $early && $price > 0;

        return [
            'access' => false,
            'reason' => $early ? 'early_access' : 'paywall',
            'price' => $price,
            'early_access' => $early,
            'wait_available' => $waitAvailable,
            'wait_hours' => $waitCfg['hours'],
            'wait_until' => $wait?->unlocks_at?->toIso8601String(),
        ];
    }

    /**
     * Primer capítulo "de pago" SIN desbloquear (la puerta). Todo lo posterior
     * queda bloqueado hasta pagarlo. Null si el usuario tiene plan o no hay puertas.
     * Memoizado por request (chapterAccess se llama en bucle al mapear el libro).
     */
    public function firstLockedGate(?User $user, Book $book): ?Chapter
    {
        $key = $this->accessCacheKey($user, $book);
        if (array_key_exists($key, $this->gateCache)) {
            return $this->gateCache[$key];
        }

        // Los suscriptores no tienen puerta (acceso total).
        if ($this->activePlan($user) !== null) {
            return $this->gateCache[$key] = null;
        }

        $gate = $book->chapters()
            ->where('is_published', true)
            ->orderBy('number')
            ->get()
            ->first(fn ($c) => $this->isPaidGate($book, $c) && ! $this->hasIndividualUnlock($user, $book, $c));

        return $this->gateCache[$key] = $gate;
    }

    /** ¿Es un capítulo de pago para un lector sin plan? (precio o acceso anticipado). */
    private function isPaidGate(Book $book, Chapter $chapter): bool
    {
        $early = $chapter->early_access_until && now()->lt($chapter->early_access_until);
        $freeChapters = (int) data_get($book->monetization, 'free_chapters', 0);
        $isFreeByPosition = $freeChapters > 0 && $chapter->number <= $freeChapters;

        return $early || ((int) $chapter->coin_price > 0 && ! $isFreeByPosition);
    }

    /** ¿El lector tiene este capítulo desbloqueado (pago previo o espera cumplida)? */
    private function hasIndividualUnlock(?User $user, Book $book, Chapter $chapter): bool
    {
        if (! $user) {
            return false;
        }
        if ($this->unlockedIds($user, $book)->has($chapter->id)) {
            return true;
        }
        $wait = $this->waitRows($user, $book)->get($chapter->id);

        return $wait && now()->gte($wait->unlocks_at);
    }

    private function accessCacheKey(?User $user, Book $book): string
    {
        return ($user?->id ?? 0).':'.$book->id;
    }

    /** chapter_id => true de los capítulos desbloqueados (pagados) del usuario. */
    private function unlockedIds(?User $user, Book $book): Collection
    {
        $key = $this->accessCacheKey($user, $book);

        return $this->unlockedCache[$key] ??= $user
            ? Unlock::where('user_id', $user->id)->where('book_id', $book->id)->pluck('chapter_id')->flip()
            : collect();
    }

    /** chapter_id => ChapterWaitUnlock de las esperas del usuario en el libro. */
    private function waitRows(?User $user, Book $book): Collection
    {
        $key = $this->accessCacheKey($user, $book);

        return $this->waitCache[$key] ??= $user
            ? ChapterWaitUnlock::where('user_id', $user->id)->where('book_id', $book->id)->get()->keyBy('chapter_id')
            : collect();
    }

    /** Invalida las cachés de acceso (tras un desbloqueo dentro del mismo request). */
    public function resetAccessCache(): void
    {
        $this->gateCache = [];
        $this->unlockedCache = [];
        $this->waitCache = [];
    }

    /** Config de "esperar para desbloquear": override por obra → global. */
    public function waitConfig(Book $book): array
    {
        $enabled = data_get($book->monetization, 'wait_unlock_enabled');
        $hours = data_get($book->monetization, 'wait_unlock_hours');

        return [
            'enabled' => $enabled !== null ? (bool) $enabled : (bool) PlatformSettings::get('unlocks.wait_enabled'),
            'hours' => $hours !== null ? max(1, (int) $hours) : (int) PlatformSettings::get('unlocks.wait_hours'),
        ];
    }

    /**
     * Inicia (o devuelve) la espera de un capítulo para el lector. Idempotente:
     * si ya estaba esperando NO reinicia el contador.
     */
    public function startWait(User $user, Book $book, Chapter $chapter): ChapterWaitUnlock
    {
        $cfg = $this->waitConfig($book);

        $wait = ChapterWaitUnlock::firstOrCreate(
            ['user_id' => $user->id, 'chapter_id' => $chapter->id],
            ['book_id' => $book->id, 'unlocks_at' => now()->addHours($cfg['hours'])],
        );

        // La reserva cambió el estado de acceso → invalida cachés por-request.
        $this->resetAccessCache();

        return $wait;
    }

    /** Precio efectivo con descuentos (suscripción premium + pack). */
    public function effectivePrice(User $user, Collection $chapters, ?int $packSize = null, ?Book $book = null): array
    {
        $base = $chapters->sum(fn ($c) => (int) $c->coin_price);

        $packDiscountPct = 0;
        if ($packSize && $book) {
            $packDiscountPct = (int) data_get($book->monetization, "pack_discount_pct.{$packSize}", 0);
        }

        $subDiscountPct = 0;
        if ($this->activePlan($user)) {
            $subDiscountPct = (int) PlatformSettings::get('subs.premium_unlock_discount_pct');
        }

        // Los descuentos no se acumulan multiplicativamente: se aplica el mayor.
        $discountPct = max($packDiscountPct, $subDiscountPct);
        $total = (int) ceil($base * (100 - $discountPct) / 100);

        return ['base' => $base, 'discount_pct' => $discountPct, 'total' => max(0, $total)];
    }

    /**
     * Desbloquea un conjunto de capítulos pagando con monedas.
     * $useReward: cuántas reward intenta usar el cliente (se recorta por
     * el % máximo configurable y nunca en acceso anticipado).
     */
    public function unlock(User $user, Book $book, Collection $chapters, string $source, ?int $packSize, int $useReward, string $idempotencyKey): array
    {
        if ($chapters->isEmpty()) {
            throw ValidationException::withMessages(['chapters' => ['No hay capítulos para desbloquear.']]);
        }

        $hasEarly = $chapters->contains(fn ($c) => $c->early_access_until && now()->lt($c->early_access_until));
        $pricing = $this->effectivePrice($user, $chapters, $packSize, $book);
        $total = $pricing['total'];

        // Límite de reward por desbloqueo (% configurable) y prohibición en early access
        $maxRewardPct = (int) PlatformSettings::get('rewards.max_pct_per_unlock');
        $maxReward = $hasEarly ? 0 : intdiv($total * $maxRewardPct, 100);
        $rewardToUse = max(0, min($useReward, $maxReward));
        $premiumToUse = $total - $rewardToUse;

        $result = DB::transaction(function () use ($user, $book, $chapters, $source, $packSize, $total, $premiumToUse, $rewardToUse, $pricing, $idempotencyKey) {
            // Idempotencia a nivel de operación completa
            if (Unlock::where('user_id', $user->id)->whereIn('chapter_id', $chapters->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['chapters' => ['Alguno de estos capítulos ya está desbloqueado.']]);
            }

            // Débitos (cada uno con su clave derivada — reintento seguro)
            if ($premiumToUse > 0) {
                $this->wallets->move($user->id, 'premium', -$premiumToUse, 'unlock', $idempotencyKey.':p', [
                    'book_id' => $book->id, 'chapters' => $chapters->pluck('id')->all(),
                ]);
            }
            if ($rewardToUse > 0) {
                $this->wallets->move($user->id, 'reward', -$rewardToUse, 'unlock', $idempotencyKey.':r', [
                    'book_id' => $book->id, 'chapters' => $chapters->pluck('id')->all(),
                ]);
            }

            // Unlocks permanentes (prorrateo del gasto entre capítulos)
            $n = $chapters->count();
            $premiumEach = intdiv($premiumToUse, $n);
            $rewardEach = intdiv($rewardToUse, $n);
            foreach ($chapters as $i => $chapter) {
                Unlock::create([
                    'user_id' => $user->id,
                    'book_id' => $book->id,
                    'chapter_id' => $chapter->id,
                    // el residuo del prorrateo va al primer capítulo
                    'coins_premium_spent' => $premiumEach + ($i === 0 ? $premiumToUse % $n : 0),
                    'coins_reward_spent' => $rewardEach + ($i === 0 ? $rewardToUse % $n : 0),
                    'source' => $source,
                    'pack_size' => $packSize,
                ]);
            }

            // Reparto al creador: SOLO la porción pagada con monedas Premium
            $split = null;
            if ($premiumToUse > 0 && $book->creator_id) {
                $split = $this->settleCreatorShare($user, $book, $premiumToUse, $idempotencyKey);
            }

            return [
                'unlocked' => $chapters->pluck('id')->all(),
                'pricing' => $pricing,
                'paid' => ['premium' => $premiumToUse, 'reward' => $rewardToUse, 'total' => $total],
                'creator_share' => $split,
            ];
        });

        // El desbloqueo cambió el acceso → invalida cachés por si se recalcula.
        $this->resetAccessCache();

        return $result;
    }

    /** Reparte el gasto premium entre creador y plataforma según nivel del creador. */
    private function settleCreatorShare(User $reader, Book $book, int $premiumCoins, string $idempotencyKey): array
    {
        $creator = User::find($book->creator_id);
        $sharePct = PlatformSettings::creatorSharePct($creator?->creator_level);

        $usdPerCoin = (float) PlatformSettings::get('coins.premium_usd_per_coin');
        $coinToUsd = (float) PlatformSettings::get('creator.coin_to_usd');

        $grossUsd = round($premiumCoins * $usdPerCoin, 4);
        $creatorUsd = round($grossUsd * $sharePct / 100, 4);
        $platformUsd = round($grossUsd - $creatorUsd, 4);
        $creatorCoins = (int) floor($creatorUsd / $coinToUsd);

        $event = RevenueEvent::create([
            'category' => 'unlock_commission',
            'gross_usd' => round($grossUsd, 2),
            'platform_usd' => round($platformUsd, 2),
            'creator_usd' => round($creatorUsd, 2),
            'user_id' => $reader->id,
            'creator_id' => $creator?->id,
            'meta' => ['book_id' => $book->id, 'premium_coins' => $premiumCoins, 'share_pct' => $sharePct],
        ]);

        if ($creator) {
            $this->creatorLedger->creditPending(
                $creator->id,
                $creatorCoins,
                'unlock',
                $event->id,
                $idempotencyKey.':creator',
                ['book_id' => $book->id, 'reader_id' => $reader->id],
            );
        }

        return ['creator_coins' => $creatorCoins, 'share_pct' => $sharePct];
    }
}
