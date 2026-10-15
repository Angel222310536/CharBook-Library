<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ===== Limpieza automática del centro de notificaciones =====
// Cada noche a las 3:00 AM borra:
//   - notificaciones LEÍDAS de más de 30 días
//   - notificaciones SIN LEER de más de 60 días
Schedule::command('notifications:purge')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// ===== Monetización =====
// Libera Creator Coins cuya retención antifraude venció (pending → available)
Schedule::command('charbook:release-creator-holds')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Creator Fund del mes anterior, el día 2 de cada mes a las 4:00
Schedule::command('charbook:creator-fund ' . now()->subMonth()->format('Y-m'))
    ->monthlyOn(2, '04:00')
    ->withoutOverlapping()
    ->runInBackground();
