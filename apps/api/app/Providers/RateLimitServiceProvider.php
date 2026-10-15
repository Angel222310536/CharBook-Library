<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ===== API general (público) =====
        // 60 requests/minuto por IP — suficiente para navegación normal
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // ===== Búsqueda instantánea (más permisivo, es alta frecuencia) =====
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // ===== Lookup metadata (Google Books / Open Library) =====
        // Restrictivo: cada lookup llama APIs externas
        RateLimiter::for('lookup', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // ===== Auth-sensible (login, OAuth) =====
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // ===== Mutaciones del usuario (reviews, favoritos) =====
        RateLimiter::for('user-mutations', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }
}
