<?php

namespace App\Services;

use App\Models\CreatorCoinTransaction;
use App\Models\CreatorEarning;
use App\Models\CreatorWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Ledger de Creator Coins.
 * Las ganancias entran como "pending" (retención configurable contra
 * chargebacks) y un job diario las libera a "available".
 */
class CreatorLedgerService
{
    public function lockWallet(int $creatorId): CreatorWallet
    {
        CreatorWallet::firstOrCreate(['user_id' => $creatorId]);

        return CreatorWallet::where('user_id', $creatorId)->lockForUpdate()->first();
    }

    /** Acredita Creator Coins en estado pendiente (con retención). */
    public function creditPending(
        int $creatorId,
        int $coins,
        string $source,
        ?int $revenueEventId = null,
        ?string $idempotencyKey = null,
        array $meta = [],
    ): ?CreatorCoinTransaction {
        if ($coins <= 0) {
            return null;
        }

        return DB::transaction(function () use ($creatorId, $coins, $source, $revenueEventId, $idempotencyKey, $meta) {
            if ($idempotencyKey && CreatorCoinTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                return CreatorCoinTransaction::where('idempotency_key', $idempotencyKey)->first();
            }

            $wallet = $this->lockWallet($creatorId);
            $holdDays = (int) PlatformSettings::get('payouts.hold_days');

            $wallet->increment('pending_coins', $coins);
            $wallet->increment('lifetime_coins', $coins);

            $tx = CreatorCoinTransaction::create([
                'creator_id' => $creatorId,
                'amount' => $coins,
                'status' => 'pending',
                'source' => $source,
                'available_at' => now()->addDays($holdDays),
                'revenue_event_id' => $revenueEventId,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta ?: null,
            ]);

            // Agregado diario para el panel del creador
            $usd = round($coins * (float) PlatformSettings::get('creator.coin_to_usd'), 2);
            CreatorEarning::upsert(
                [['creator_id' => $creatorId, 'day' => today()->toDateString(), 'source' => $source, 'coins' => $coins, 'usd_value' => $usd, 'created_at' => now(), 'updated_at' => now()]],
                ['creator_id', 'day', 'source'],
                ['coins' => DB::raw('creator_earnings.coins + excluded.coins'), 'usd_value' => DB::raw('creator_earnings.usd_value + excluded.usd_value'), 'updated_at' => now()],
            );

            return $tx;
        });
    }

    /** Libera a "available" todo lo pendiente cuya retención venció. Devuelve filas liberadas. */
    public function releaseMatured(): int
    {
        $due = CreatorCoinTransaction::where('status', 'pending')
            ->where('available_at', '<=', now())
            ->get()
            ->groupBy('creator_id');

        $released = 0;
        foreach ($due as $creatorId => $txs) {
            DB::transaction(function () use ($creatorId, $txs, &$released) {
                $wallet = $this->lockWallet($creatorId);
                $sum = $txs->sum('amount');
                CreatorCoinTransaction::whereIn('id', $txs->pluck('id'))->update(['status' => 'available']);
                $wallet->decrement('pending_coins', $sum);
                $wallet->increment('available_coins', $sum);
                $released += $txs->count();
            });
        }

        return $released;
    }

    /**
     * Clawback por reembolso/disputa: revierte primero pendiente, luego disponible.
     * Si el creador ya retiró las ganancias, el faltante queda como deuda EXPLÍCITA
     * (available_coins negativo, registrado y logueado) — nunca un descuadre silencioso;
     * lo cubrirán ganancias futuras antes de poder retirar de nuevo.
     *
     * Devuelve el desglose de lo revertido.
     */
    public function clawback(int $creatorId, int $coins, array $meta = []): array
    {
        if ($coins <= 0) {
            return ['from_pending' => 0, 'from_available' => 0, 'debt' => 0];
        }

        return DB::transaction(function () use ($creatorId, $coins, $meta) {
            $wallet = $this->lockWallet($creatorId);

            $fromPending = min($coins, max(0, $wallet->pending_coins));
            $remainder = $coins - $fromPending;
            // El resto sale de disponible aunque lo deje negativo (deuda explícita).
            $debt = max(0, $remainder - max(0, $wallet->available_coins));

            $wallet->update([
                'pending_coins' => $wallet->pending_coins - $fromPending,
                'available_coins' => $wallet->available_coins - $remainder,
            ]);

            CreatorCoinTransaction::create([
                'creator_id' => $creatorId,
                'amount' => -$coins,
                'status' => 'reversed',
                'source' => 'clawback',
                'meta' => array_merge($meta, [
                    'from_pending' => $fromPending,
                    'from_available' => $remainder,
                    'debt' => $debt,
                ]),
            ]);

            if ($debt > 0) {
                Log::warning("[clawback] creador {$creatorId}: deuda de {$debt} Creator Coins (ya retiradas). available_coins negativo; se cubrirá con ganancias futuras.");
            }

            return ['from_pending' => $fromPending, 'from_available' => $remainder, 'debt' => $debt];
        });
    }

    /**
     * Revierte las ganancias de Creator Coins generadas por los desbloqueos de un
     * comprador cuya compra de monedas fue reembolsada/disputada. Cierra el vector
     * de fraude: comprar monedas → desbloquear a un creador cómplice → pedir refund.
     *
     * $fraction = porción reembolsada (1.0 = total). Marca las transacciones
     * originales para no revertirlas dos veces (idempotente ante refund + dispute).
     */
    public function clawbackBuyerUnlockEarnings(int $buyerId, float $fraction, array $eventMeta = []): array
    {
        $fraction = max(0.0, min(1.0, $fraction));
        if ($fraction <= 0.0) {
            return [];
        }

        // Ganancias por unlock de este comprador aún no revertidas por un refund previo.
        $txs = CreatorCoinTransaction::where('source', 'unlock')
            ->where('amount', '>', 0)
            ->whereIn('status', ['pending', 'available'])
            ->get()
            ->filter(fn ($t) => (int) ($t->meta['reader_id'] ?? 0) === $buyerId
                && ! ($t->meta['refund_reversed'] ?? false));

        $results = [];

        foreach ($txs->groupBy('creator_id') as $creatorId => $group) {
            $sum = (int) $group->sum('amount');
            $coins = (int) round($sum * $fraction);
            if ($coins <= 0) {
                continue;
            }

            $breakdown = $this->clawback((int) $creatorId, $coins, array_merge($eventMeta, [
                'reason' => 'buyer_refund',
                'buyer_id' => $buyerId,
                'fraction' => $fraction,
            ]));

            // Marcar originales para evitar doble reversión (refund seguido de dispute).
            foreach ($group as $orig) {
                $meta = $orig->meta ?? [];
                $meta['refund_reversed'] = true;
                $orig->update(['meta' => $meta]);
            }

            $results[] = ['creator_id' => (int) $creatorId, 'clawed' => $coins] + $breakdown;
        }

        return $results;
    }

    /** Debita saldo disponible para un retiro. Lanza si no alcanza. */
    public function debitForPayout(int $creatorId, int $coins, int $payoutId): void
    {
        DB::transaction(function () use ($creatorId, $coins, $payoutId) {
            $wallet = $this->lockWallet($creatorId);

            if ($wallet->available_coins < $coins) {
                throw ValidationException::withMessages(['payout' => ['Saldo disponible insuficiente.']]);
            }

            $wallet->decrement('available_coins', $coins);

            CreatorCoinTransaction::create([
                'creator_id' => $creatorId,
                'amount' => -$coins,
                'status' => 'paid_out',
                'source' => 'payout',
                'meta' => ['payout_id' => $payoutId],
            ]);
        });
    }
}
