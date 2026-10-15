<?php

namespace App\Services;

use App\Models\CoinPackage;
use App\Models\Payout;
use App\Models\RevenueEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

/**
 * Integración Stripe (procesador principal) + Stripe Connect Express (creadores).
 *
 * - Todos los pagos de lectores entran a la cuenta plataforma vía Checkout.
 * - Los creadores cobran SOLO por Transfers automáticos de Connect (sin pagos manuales).
 * - CharBook nunca almacena datos bancarios: KYC y banca viven en Stripe.
 */
class StripeService
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly CreatorLedgerService $creatorLedger,
    ) {}

    /**
     * Cliente lazy: los webhooks que no llaman a la API de Stripe
     * (p. ej. acreditar monedas) funcionan aunque no haya clave en dev.
     */
    private function stripe(): StripeClient
    {
        if (! $this->client) {
            $secret = config('services.stripe.secret');
            if (! $secret) {
                throw new \RuntimeException('STRIPE_SECRET no configurado: esta operación requiere la clave de Stripe.');
            }
            $this->client = new StripeClient($secret);
        }

        return $this->client;
    }

    // =====================================================
    // Clientes y Checkout
    // =====================================================

    public function ensureCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe()->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['charbook_user_id' => $user->id],
        ]);
        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    /** Checkout para un paquete de monedas (mode=payment). */
    public function checkoutCoinPackage(User $user, CoinPackage $package, string $successUrl, string $cancelUrl): string
    {
        $lineItem = $package->stripe_price_id
            ? ['price' => $package->stripe_price_id, 'quantity' => 1]
            : [
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) round($package->price_usd * 100),
                    'product_data' => ['name' => "CharCoins · {$package->name}"],
                ],
                'quantity' => 1,
            ];

        $meta = [
            'kind' => 'coin_package',
            'charbook_user_id' => $user->id,
            'package_id' => $package->id,
        ];

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $this->ensureCustomer($user),
            'line_items' => [$lineItem],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $meta,
            // Propaga la metadata al PaymentIntent → Charge, para que un refund/dispute
            // pueda resolver comprador y paquete sin llamadas extra a la API.
            'payment_intent_data' => ['metadata' => $meta],
        ]);

        return $session->url;
    }

    /** Checkout de suscripción (premium | premium_plus). */
    public function checkoutSubscription(User $user, string $plan, string $successUrl, string $cancelUrl): string
    {
        $priceId = $plan === 'premium_plus'
            ? PlatformSettings::get('subs.premium_plus_price_id')
            : PlatformSettings::get('subs.premium_price_id');

        $lineItem = $priceId
            ? ['price' => $priceId, 'quantity' => 1]
            : [
                // Fallback dev sin Prices creados en Stripe: precio inline recurrente
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) round((float) PlatformSettings::get(
                        $plan === 'premium_plus' ? 'subs.premium_plus_price_usd' : 'subs.premium_price_usd'
                    ) * 100),
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => $plan === 'premium_plus' ? 'CharBook Premium Plus' : 'CharBook Premium'],
                ],
                'quantity' => 1,
            ];

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $this->ensureCustomer($user),
            'line_items' => [$lineItem],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['kind' => 'subscription', 'charbook_user_id' => $user->id, 'plan' => $plan],
            'subscription_data' => [
                'metadata' => ['charbook_user_id' => $user->id, 'plan' => $plan],
            ],
        ]);

        return $session->url;
    }

    /**
     * Stripe Customer Portal: gestionar suscripción, método de pago,
     * facturas y cancelaciones. Redirección segura; nada hardcodeado.
     */
    public function billingPortalUrl(User $user, string $returnUrl): string
    {
        $session = $this->stripe()->billingPortal->sessions->create([
            'customer' => $this->ensureCustomer($user),
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    // =====================================================
    // Webhooks (única fuente de verdad de pagos)
    // =====================================================

    public function handleWebhook(\Stripe\Event $event): void
    {
        // Idempotencia global del webhook
        if (RevenueEvent::where('stripe_event_id', $event->id)->exists()) {
            return;
        }

        match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($event),
            'invoice.paid' => $this->onInvoicePaid($event),
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->onSubscriptionChanged($event),
            'charge.refunded' => $this->onRefund($event),
            'charge.dispute.created' => $this->onDispute($event),
            'account.updated' => $this->onConnectAccountUpdated($event),
            default => Log::info("[stripe] evento ignorado: {$event->type}"),
        };
    }

    private function onCheckoutCompleted(\Stripe\Event $event): void
    {
        $session = $event->data->object;
        $meta = $session->metadata?->toArray() ?? [];
        $userId = (int) ($meta['charbook_user_id'] ?? 0);

        if (($meta['kind'] ?? null) === 'coin_package' && $userId) {
            $package = CoinPackage::find($meta['package_id'] ?? null);
            if (! $package) {
                return;
            }

            DB::transaction(function () use ($session, $event, $userId, $package) {
                $revenue = RevenueEvent::create([
                    'category' => 'coin_purchase',
                    'gross_usd' => ($session->amount_total ?? 0) / 100,
                    'platform_usd' => ($session->amount_total ?? 0) / 100,
                    'user_id' => $userId,
                    'stripe_event_id' => $event->id,
                    'stripe_object_id' => $session->id,
                    'meta' => ['package_id' => $package->id],
                ]);

                $this->wallets->move(
                    $userId,
                    'premium',
                    $package->coins + $package->bonus_coins,
                    'purchase',
                    "stripe:{$session->id}",
                    ['package_id' => $package->id],
                    $revenue,
                );
            });
        }

        if (($meta['kind'] ?? null) === 'subscription' && $userId) {
            $this->syncSubscriptionFromStripe($session->subscription, $userId, $meta['plan'] ?? 'premium');
            // El revenue se registra en invoice.paid (cubre también renovaciones);
            // aquí solo dejamos traza de la sesión.
            RevenueEvent::create([
                'category' => ($meta['plan'] ?? 'premium') === 'premium_plus' ? 'subscription_plus' : 'subscription_premium',
                'gross_usd' => 0,
                'user_id' => $userId,
                'stripe_event_id' => $event->id,
                'stripe_object_id' => $session->id,
                'meta' => ['note' => 'checkout completado; importe en invoice.paid'],
            ]);
        }
    }

    private function onInvoicePaid(\Stripe\Event $event): void
    {
        $invoice = $event->data->object;
        $subId = $invoice->subscription ?? null;
        if (! $subId) {
            return;
        }

        $sub = Subscription::where('stripe_subscription_id', $subId)->first();
        $stripeSub = $this->stripe()->subscriptions->retrieve($subId);
        $meta = $stripeSub->metadata?->toArray() ?? [];
        $userId = $sub?->user_id ?? (int) ($meta['charbook_user_id'] ?? 0);
        $plan = $sub?->plan ?? ($meta['plan'] ?? 'premium');

        if (! $sub && $userId) {
            $this->syncSubscriptionFromStripe($subId, $userId, $plan);
        } elseif ($sub) {
            $sub->update([
                'status' => 'active',
                'current_period_end' => $stripeSub->current_period_end
                    ? now()->createFromTimestamp($stripeSub->current_period_end)
                    : null,
            ]);
            $this->syncUserTier($sub->user);
        }

        RevenueEvent::create([
            'category' => $plan === 'premium_plus' ? 'subscription_plus' : 'subscription_premium',
            'gross_usd' => ($invoice->amount_paid ?? 0) / 100,
            'platform_usd' => ($invoice->amount_paid ?? 0) / 100, // el fund se descuenta en la distribución mensual
            'user_id' => $userId ?: null,
            'stripe_event_id' => $event->id,
            'stripe_object_id' => $invoice->id,
            'meta' => ['subscription' => $subId, 'plan' => $plan],
        ]);
    }

    private function onSubscriptionChanged(\Stripe\Event $event): void
    {
        $stripeSub = $event->data->object;
        $sub = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();
        if (! $sub) {
            return;
        }

        $sub->update([
            'status' => in_array($stripeSub->status, ['active', 'trialing'], true) ? 'active'
                : ($stripeSub->status === 'past_due' ? 'past_due' : 'canceled'),
            'cancel_at_period_end' => (bool) $stripeSub->cancel_at_period_end,
            'current_period_end' => $stripeSub->current_period_end
                ? now()->createFromTimestamp($stripeSub->current_period_end)
                : null,
        ]);

        $this->syncUserTier($sub->user);

        RevenueEvent::create([
            'category' => 'other',
            'user_id' => $sub->user_id,
            'stripe_event_id' => $event->id,
            'stripe_object_id' => $stripeSub->id,
            'meta' => ['type' => $event->type, 'status' => $stripeSub->status],
        ]);
    }

    /**
     * Reembolso: revierte las monedas del comprador y hace clawback de las
     * ganancias de creador generadas por sus desbloqueos (proporcional al refund).
     */
    private function onRefund(\Stripe\Event $event): void
    {
        $charge = $event->data->object;
        $amount = (int) ($charge->amount ?? 0);
        $refunded = (int) ($charge->amount_refunded ?? 0);
        $fraction = $amount > 0 ? min(1.0, $refunded / $amount) : 1.0;

        $this->reversePurchase(
            $event,
            (string) $charge->id,
            $this->chargeMetadata($charge),
            $fraction,
            $refunded / 100,
            'refund',
        );
    }

    /** Disputa (chargeback): se trata como reversión del cargo asociado. */
    private function onDispute(\Stripe\Event $event): void
    {
        $dispute = $event->data->object;
        $amount = (int) ($dispute->amount ?? 0);
        $chargeAmount = $amount;
        $meta = [];

        if (! empty($dispute->charge)) {
            try {
                $charge = $this->stripe()->charges->retrieve($dispute->charge);
                $meta = $this->chargeMetadata($charge);
                $chargeAmount = (int) ($charge->amount ?? $amount);
            } catch (\Throwable $e) {
                Log::warning('[stripe] dispute: no se pudo recuperar el charge — ' . $e->getMessage());
            }
        }
        // Fallback para eventos sintéticos (tests): metadata embebida en el dispute.
        if (! $meta && isset($dispute->metadata)) {
            $meta = $dispute->metadata instanceof \Stripe\StripeObject ? $dispute->metadata->toArray() : (array) $dispute->metadata;
        }

        $fraction = $chargeAmount > 0 ? min(1.0, $amount / $chargeAmount) : 1.0;

        $this->reversePurchase(
            $event,
            (string) ($dispute->charge ?? $dispute->id),
            $meta,
            $fraction,
            $amount / 100,
            'dispute',
        );
    }

    /** Extrae la metadata de un charge como array plano. */
    private function chargeMetadata($charge): array
    {
        if (! isset($charge->metadata)) {
            return [];
        }

        return $charge->metadata instanceof \Stripe\StripeObject
            ? $charge->metadata->toArray()
            : (array) $charge->metadata;
    }

    /**
     * Reversión unificada de refund/dispute:
     *  - Registra el evento contable negativo (idempotente por stripe_event_id).
     *  - Si fue compra de monedas: revierte las monedas del comprador (permite negativo)
     *    y hace clawback de las ganancias de creador por sus desbloqueos (× fracción).
     */
    private function reversePurchase(\Stripe\Event $event, string $objectId, array $meta, float $fraction, float $refundedUsd, string $kind): void
    {
        $userId = (int) ($meta['charbook_user_id'] ?? 0);

        DB::transaction(function () use ($event, $objectId, $meta, $fraction, $refundedUsd, $kind, $userId) {
            RevenueEvent::create([
                'category' => 'other',
                'gross_usd' => -round($refundedUsd, 2),
                'platform_usd' => -round($refundedUsd, 2),
                'user_id' => $userId ?: null,
                'stripe_event_id' => $event->id,
                'stripe_object_id' => $objectId,
                'meta' => array_merge(['type' => $kind, 'fraction' => round($fraction, 4)], $meta),
            ]);

            if (($meta['kind'] ?? null) === 'coin_package' && $userId) {
                $package = CoinPackage::find($meta['package_id'] ?? null);
                if ($package) {
                    $coins = (int) round(($package->coins + $package->bonus_coins) * $fraction);
                    if ($coins > 0) {
                        $this->wallets->reverse($userId, 'premium', $coins, 'refund', "refund:{$event->id}", [
                            'type' => $kind, 'object' => $objectId,
                        ]);
                    }
                }

                // Cierra el vector de fraude: revertir lo que ganaron los creadores
                // por desbloqueos de este comprador con las monedas ahora reembolsadas.
                $this->creatorLedger->clawbackBuyerUnlockEarnings($userId, $fraction, [
                    'event' => $event->id, 'kind' => $kind, 'object' => $objectId,
                ]);
            }
        });

        Log::info("[stripe] {$kind} {$objectId} procesado (fracción " . round($fraction, 2) . ").");
    }

    private function syncSubscriptionFromStripe(?string $stripeSubId, int $userId, string $plan): void
    {
        if (! $stripeSubId) {
            return;
        }
        $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSubId);

        Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSubId],
            [
                'user_id' => $userId,
                'plan' => $plan,
                'status' => in_array($stripeSub->status, ['active', 'trialing'], true) ? 'active' : $stripeSub->status,
                'current_period_end' => $stripeSub->current_period_end
                    ? now()->createFromTimestamp($stripeSub->current_period_end)
                    : null,
                'cancel_at_period_end' => (bool) $stripeSub->cancel_at_period_end,
            ],
        );

        $this->syncUserTier(User::find($userId));
    }

    /** Mantiene users.subscription_tier (legacy) sincronizado con la tabla subscriptions. */
    public function syncUserTier(?User $user): void
    {
        if (! $user) {
            return;
        }
        $plans = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('plan');

        $tier = $plans->contains('premium_plus') ? 'premium_plus'
            : ($plans->contains('premium') ? 'premium' : 'free');

        $user->update(['subscription_tier' => $tier]);
    }

    // =====================================================
    // Stripe Connect Express (creadores)
    // =====================================================

    /**
     * stripe-php emite un aviso de deprecación (trigger_error) en el namespace
     * v1 Accounts ("use Accounts v2") y Laravel lo convierte en excepción.
     * Las cuentas v1 + Express siguen plenamente soportadas: ejecutamos la
     * llamada con los avisos de deprecación silenciados, sin tocar errores reales.
     */
    private function withoutDeprecationNoise(callable $fn): mixed
    {
        set_error_handler(fn () => true, E_DEPRECATED | E_USER_DEPRECATED | E_USER_NOTICE | E_USER_WARNING);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** Crea (si hace falta) la cuenta Express y devuelve el link de onboarding/KYC. */
    public function connectOnboardingLink(User $creator, string $returnUrl, string $refreshUrl): string
    {
        if (! $creator->stripe_account_id) {
            // Forma moderna (sin 'type' deprecado): cuenta con dashboard Express,
            // la plataforma asume fees/pérdidas — equivalente a Connect Express.
            $account = $this->withoutDeprecationNoise(fn () => $this->stripe()->accounts->create([
                'controller' => [
                    'stripe_dashboard' => ['type' => 'express'],
                    'fees' => ['payer' => 'application'],
                    'losses' => ['payments' => 'application'],
                ],
                'email' => $creator->email,
                'capabilities' => ['transfers' => ['requested' => true]],
                'metadata' => ['charbook_user_id' => $creator->id],
            ]));
            $creator->update(['stripe_account_id' => $account->id]);
        }

        $link = $this->withoutDeprecationNoise(fn () => $this->stripe()->accountLinks->create([
            'account' => $creator->stripe_account_id,
            'type' => 'account_onboarding',
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ]));

        return $link->url;
    }

    public function connectStatus(User $creator): array
    {
        if (! $creator->stripe_account_id) {
            return ['connected' => false, 'payouts_enabled' => false, 'requirements_due' => []];
        }

        $account = $this->withoutDeprecationNoise(fn () => $this->stripe()->accounts->retrieve($creator->stripe_account_id));

        // Sincroniza el flag local
        \App\Models\CreatorWallet::firstOrCreate(['user_id' => $creator->id])
            ->update(['payouts_enabled' => (bool) $account->payouts_enabled]);

        return [
            'connected' => true,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'details_submitted' => (bool) $account->details_submitted,
            'requirements_due' => $account->requirements->currently_due ?? [],
        ];
    }

    private function onConnectAccountUpdated(\Stripe\Event $event): void
    {
        $account = $event->data->object;
        $user = User::where('stripe_account_id', $account->id)->first();
        if ($user) {
            \App\Models\CreatorWallet::firstOrCreate(['user_id' => $user->id])
                ->update(['payouts_enabled' => (bool) $account->payouts_enabled]);
        }
    }

    /** Ejecuta el retiro vía Transfer a la cuenta Connect del creador. */
    public function processPayout(Payout $payout): Payout
    {
        $creator = $payout->creator;
        if (! $creator?->stripe_account_id) {
            throw ValidationException::withMessages(['payout' => ['El creador no tiene Stripe Connect configurado.']]);
        }

        $payout->update(['status' => 'processing']);

        try {
            $transfer = $this->stripe()->transfers->create([
                'amount' => (int) round(($payout->amount_usd - $payout->fee_usd) * 100),
                'currency' => 'usd',
                'destination' => $creator->stripe_account_id,
                'metadata' => ['charbook_payout_id' => $payout->id, 'creator_id' => $creator->id],
            ], [
                'idempotency_key' => "charbook-payout-{$payout->id}",
            ]);

            $payout->update([
                'status' => 'paid',
                'stripe_transfer_id' => $transfer->id,
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $payout->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            // Devolver las monedas al saldo disponible
            $this->creatorLedger->lockWallet($creator->id);
            \App\Models\CreatorWallet::where('user_id', $creator->id)
                ->increment('available_coins', $payout->coins_converted);
            throw $e;
        }

        return $payout->fresh();
    }
}
