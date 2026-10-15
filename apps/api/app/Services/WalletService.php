<?php

namespace App\Services;

use App\Models\CoinTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Billetera del lector (CharCoins Premium + Reward).
 *
 * Reglas de oro:
 * - Todo movimiento pasa por una transacción SQL con la fila de wallet bloqueada (FOR UPDATE).
 * - Nunca se actualiza un saldo sin escribir la fila de ledger correspondiente.
 * - Idempotencia por clave única: reintentos no duplican movimientos.
 * - El saldo jamás se acepta del cliente.
 */
class WalletService
{
    /** Crea (si no existe) y bloquea la wallet del usuario. Llamar SIEMPRE dentro de DB::transaction. */
    public function lockWallet(int $userId): Wallet
    {
        Wallet::firstOrCreate(['user_id' => $userId]);

        return Wallet::where('user_id', $userId)->lockForUpdate()->first();
    }

    /**
     * Movimiento atómico. $amount positivo acredita, negativo debita.
     * Devuelve la transacción de ledger (o la existente si la idempotency_key ya se usó).
     */
    public function move(
        int $userId,
        string $currency, // premium | reward
        int $amount,
        string $type,
        ?string $idempotencyKey = null,
        array $meta = [],
        ?object $reference = null,
    ): CoinTransaction {
        return DB::transaction(function () use ($userId, $currency, $amount, $type, $idempotencyKey, $meta, $reference) {
            if ($idempotencyKey) {
                $existing = CoinTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing; // reintento: no duplicar
                }
            }

            $wallet = $this->lockWallet($userId);
            $column = $currency === 'premium' ? 'premium_balance' : 'reward_balance';
            $newBalance = $wallet->{$column} + $amount;

            if ($newBalance < 0) {
                throw ValidationException::withMessages([
                    'coins' => ['Saldo insuficiente de monedas ' . ($currency === 'premium' ? 'Premium' : 'Reward') . '.'],
                ]);
            }

            $wallet->update([$column => $newBalance]);

            return CoinTransaction::create([
                'user_id' => $userId,
                'currency' => $currency,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'type' => $type,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta ?: null,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
            ]);
        });
    }

    /**
     * Reversión por reembolso/disputa. A diferencia de move(), SÍ puede dejar el
     * saldo negativo: si el comprador ya gastó las monedas reembolsadas, el saldo
     * negativo es una deuda EXPLÍCITA registrada en el ledger (no un descuadre
     * silencioso) que impide gastar hasta recargar. Idempotente por clave.
     */
    public function reverse(int $userId, string $currency, int $coins, string $type, ?string $idempotencyKey = null, array $meta = []): ?CoinTransaction
    {
        if ($coins <= 0) {
            return null;
        }

        return DB::transaction(function () use ($userId, $currency, $coins, $type, $idempotencyKey, $meta) {
            if ($idempotencyKey) {
                $existing = CoinTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $wallet = $this->lockWallet($userId);
            $column = $currency === 'premium' ? 'premium_balance' : 'reward_balance';
            $newBalance = $wallet->{$column} - $coins;
            $wallet->update([$column => $newBalance]);

            if ($newBalance < 0) {
                Log::warning("[wallet] usuario {$userId} {$currency} en negativo ({$newBalance}) tras refund/clawback — monedas ya gastadas, deuda registrada.");
            }

            return CoinTransaction::create([
                'user_id' => $userId,
                'currency' => $currency,
                'amount' => -$coins,
                'balance_after' => $newBalance,
                'type' => $type,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta ?: null,
            ]);
        });
    }

    /** Reward coins acreditadas hoy (todas las fuentes) — para caps anti-inflación. */
    public function rewardEarnedToday(int $userId): int
    {
        return (int) CoinTransaction::where('user_id', $userId)
            ->where('currency', 'reward')
            ->where('amount', '>', 0)
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    public function rewardEarnedThisWeek(int $userId): int
    {
        return (int) CoinTransaction::where('user_id', $userId)
            ->where('currency', 'reward')
            ->where('amount', '>', 0)
            ->where('created_at', '>=', now()->startOfWeek())
            ->sum('amount');
    }

    /**
     * Acredita reward coins respetando caps diarios/semanales configurables.
     * Devuelve la cantidad realmente acreditada (puede ser 0 si el cap se alcanzó).
     */
    public function creditRewardCapped(int $userId, int $amount, string $type, ?string $idempotencyKey = null, array $meta = []): int
    {
        $dailyCap = (int) PlatformSettings::get('rewards.daily_cap');
        $weeklyCap = (int) PlatformSettings::get('rewards.weekly_cap');

        $room = min(
            max(0, $dailyCap - $this->rewardEarnedToday($userId)),
            max(0, $weeklyCap - $this->rewardEarnedThisWeek($userId)),
        );
        $grant = min($amount, $room);
        if ($grant <= 0) {
            return 0;
        }

        $this->move($userId, 'reward', $grant, $type, $idempotencyKey, $meta);

        return $grant;
    }
}
