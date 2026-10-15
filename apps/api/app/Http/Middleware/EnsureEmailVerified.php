<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea endpoints sensibles a cuentas sin verificar.
 * Devuelve 403 + payload con email_verified:false para que el frontend pueda
 * mostrar un dialog específico ("verifica tu correo para descargar").
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }
        if (! $user->email_verified_at) {
            return response()->json([
                'message' => 'Verifica tu correo electrónico para usar esta función.',
                'email_verified' => false,
                'email' => $user->email,
            ], 403);
        }
        return $next($request);
    }
}
