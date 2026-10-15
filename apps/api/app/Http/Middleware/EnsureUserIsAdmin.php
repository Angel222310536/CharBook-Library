<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permite el paso si el usuario tiene rol admin O super_admin.
 * Para endpoints que también necesitan moderator, usa el alias 'staff'.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if (! $user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return $next($request);
    }
}
