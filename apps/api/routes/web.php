<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

// Esta API es 100% JSON. La única excepción es el flow OAuth de Google
// que necesita rutas web (no API) porque depende de redirects del navegador.

Route::get('/', fn() => response()->json([
    'app' => config('app.name'),
    'environment' => app()->environment(),
    'message' => 'CharBook Library API. Visita /api para los endpoints.',
    'docs' => null,
]));

// ===== Google OAuth (flow vía navegador) =====
Route::middleware('throttle:auth')->prefix('auth/google')->group(function () {
    Route::get('/', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/mobile', [GoogleController::class, 'mobileRedirect'])->name('auth.google.mobile');
    Route::get('/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});
