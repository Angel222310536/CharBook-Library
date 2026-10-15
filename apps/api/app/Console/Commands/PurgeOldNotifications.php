<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

/**
 * Limpieza automática del centro de notificaciones.
 *
 *  - Notificaciones LEÍDAS de más de 30 días → eliminadas
 *  - Notificaciones SIN LEER de más de 60 días → eliminadas
 *
 * Se ejecuta automáticamente cada noche (ver app/Console/Kernel.php).
 */
class PurgeOldNotifications extends Command
{
    protected $signature = 'notifications:purge
                            {--read-days=30 : Días para borrar leídas}
                            {--unread-days=60 : Días para borrar sin leer}
                            {--dry-run : Solo muestra cuántas borraría sin hacerlo}';

    protected $description = 'Borra notificaciones antiguas (leídas y sin leer) según política configurable';

    public function handle(): int
    {
        $readDays = (int) $this->option('read-days');
        $unreadDays = (int) $this->option('unread-days');
        $dry = (bool) $this->option('dry-run');

        $readCutoff = now()->subDays($readDays);
        $unreadCutoff = now()->subDays($unreadDays);

        $readQuery = Notification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $readCutoff);

        $unreadQuery = Notification::query()
            ->whereNull('read_at')
            ->where('created_at', '<', $unreadCutoff);

        $readCount = $readQuery->count();
        $unreadCount = $unreadQuery->count();

        if ($dry) {
            $this->info("[DRY-RUN] Borraría {$readCount} leídas (>{$readDays}d) y {$unreadCount} sin leer (>{$unreadDays}d).");
            return self::SUCCESS;
        }

        $readDeleted = $readQuery->delete();
        $unreadDeleted = $unreadQuery->delete();

        $this->info("Eliminadas: {$readDeleted} leídas + {$unreadDeleted} sin leer = " . ($readDeleted + $unreadDeleted));
        return self::SUCCESS;
    }
}
