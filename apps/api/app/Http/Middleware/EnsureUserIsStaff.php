<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permite el paso si el usuario es parte del staff:
 * super_admin, admin o moderator.
 *
 * Usar en endpoints de moderación (reports, creator applications, salons).
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if (! $user->hasAnyRole(['admin', 'super_admin', 'moderator'])) {
            return response()->json(['message' => 'Solo staff (admin o moderador).'], 403);
        }

        return $next($request);
    }
}
