<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoinPackage;
use App\Models\Subscription;
use App\Services\PlatformSettings;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

/**
 * Checkout (suscripciones + paquetes de monedas) y webhook de Stripe.
 * El webhook es la ÚNICA fuente de verdad de pagos: el success_url del
 * cliente jamás acredita nada.
 */
class BillingController extends Controller
{
    public function __construct(private readonly StripeService $stripe) {}

    /** POST /billing/checkout { package_id } | { plan: premium|premium_plus } */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => 'required_without:plan|integer|exists:coin_packages,id',
            'plan' => 'required_without:package_id|string|in:premium,premium_plus',
        ]);

        $front = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $success = $front . '/monedas?status=success';
        $cancel = $front . '/monedas?status=cancel';

        if (isset($data['package_id'])) {
            $package = CoinPackage::where('is_active', true)->findOrFail($data['package_id']);
            $url = $this->stripe->checkoutCoinPackage($request->user(), $package, $success, $cancel);
        } else {
            $success = $front . '/premium?status=success';
            $cancel = $front . '/premium?status=cancel';
            $url = $this->stripe->checkoutSubscription($request->user(), $data['plan'], $success, $cancel);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * POST /billing/portal — sesión del Stripe Customer Portal.
     * El usuario gestiona allí su suscripción, métodos de pago y facturas.
     * Redirección segura; configuración 100% por variables de entorno.
     */
    public function portal(Request $request): JsonResponse
    {
        $front = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');

        try {
            $url = $this->stripe->billingPortalUrl($request->user(), $front . '/monedas');
        } catch (\Throwable $e) {
            Log::warning('[stripe] portal: ' . $e->getMessage());
            return response()->json([
                'message' => 'No se pudo abrir el portal de facturación. Verifica la configuración del Customer Portal en el dashboard de Stripe.',
            ], 502);
        }

        return response()->json(['url' => $url]);
    }

    /** GET /me/subscription — estado completo (amplía el endpoint legacy). */
    public function subscription(Request $request): JsonResponse
    {
        $subs = Subscription::where('user_id', $request->user()->id)
            ->latest()
            ->get(['plan', 'status', 'current_period_end', 'cancel_at_period_end']);

        return response()->json([
            'data' => [
                'tier' => $request->user()->subscription_tier ?? 'free',
                'subscriptions' => $subs,
                'prices' => [
                    'premium_usd' => PlatformSettings::get('subs.premium_price_usd'),
                    'premium_plus_usd' => PlatformSettings::get('subs.premium_plus_price_usd'),
                ],
                'premium_unlock_discount_pct' => PlatformSettings::get('subs.premium_unlock_discount_pct'),
            ],
        ]);
    }

    /**
     * POST /webhooks/stripe — firma verificada, idempotente.
     *
     * Seguridad:
     * - Si hay STRIPE_WEBHOOK_SECRET → SIEMPRE se verifica la firma (cualquier entorno).
     * - Sin secret → solo se procesa en local/testing (dev sin `stripe listen`).
     *   En producción/staging un webhook sin secret se rechaza con 500 y log crítico,
     *   de modo que el secret es de facto obligatorio fuera de desarrollo.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if ($secret) {
            try {
                $event = Webhook::constructEvent(
                    $request->getContent(),
                    $request->header('Stripe-Signature', ''),
                    $secret,
                );
            } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
                // No registramos el contenido ni la firma: nada de secretos en logs.
                Log::warning('[stripe] firma de webhook inválida — evento rechazado.');
                return response()->json(['error' => 'invalid signature'], 400);
            }
        } else {
            if (! app()->environment(['local', 'testing'])) {
                Log::critical('[stripe] STRIPE_WEBHOOK_SECRET ausente en entorno "' . app()->environment() . '". Webhook rechazado por seguridad.');
                return response()->json(['error' => 'webhook signing secret not configured'], 500);
            }
            Log::warning('[stripe] webhook procesado SIN verificación de firma (solo dev). Configura STRIPE_WEBHOOK_SECRET para producción.');
            $event = \Stripe\Event::constructFrom(json_decode($request->getContent(), true));
        }

        try {
            $this->stripe->handleWebhook($event);
        } catch (\Throwable $e) {
            Log::error("[stripe] error procesando {$event->type}: {$e->getMessage()}");
            return response()->json(['error' => 'processing failed'], 500); // Stripe reintenta
        }

        return response()->json(['received' => true]);
    }
}
