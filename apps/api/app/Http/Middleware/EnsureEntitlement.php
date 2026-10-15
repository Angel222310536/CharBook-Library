<?php

namespace App\Http\Middleware;

use App\Services\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege endpoints por entitlement: `->middleware('entitlement:advanced_profile')`.
 * Devuelve 403 si el plan del usuario no alcanza. (Seguridad server-side: ocultar
 * el botón en el front NO basta.)
 */
class EnsureEntitlement
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! Entitlements::can($request->user(), $feature)) {
            $required = Entitlements::FEATURES[$feature] ?? 'premium_plus';
            abort(403, $required === 'premium_plus'
                ? 'Esta función es exclusiva de Premium Plus.'
                : 'Esta función es exclusiva de Premium.');
        }

        return $next($request);
    }
}
