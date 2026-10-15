<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza Accept: application/json en todas las peticiones a la API.
 * Sin esto, una petición sin token a una ruta auth:sanctum hace que Laravel
 * intente redirigir a la ruta web 'login' (inexistente) y devuelva 500 con
 * stack trace, en vez de un 401 JSON limpio.
 */
class ForceJsonAccept
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
