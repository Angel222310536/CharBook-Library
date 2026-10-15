<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Añade headers de seguridad a TODAS las respuestas.
 * Aplicado globalmente vía bootstrap/app.php.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Previene clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Previene MIME-sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Política de referrer estricta
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy (antes Feature-Policy)
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), interest-cohort=()'
        );

        // HSTS solo en HTTPS (producción)
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // CSP básica — permite que el dashboard de Telescope etc. no rompa
        // Tu API solo sirve JSON, así que CSP estricta es viable.
        // Cliente real (Next.js) está en otro dominio.
        if (! $request->is('telescope*') && ! $request->is('horizon*')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'"
            );
        }

        // Quita headers que delatan tu stack
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
