<?php

namespace App\Console\Commands;

use App\Services\CreatorLedgerService;
use Illuminate\Console\Command;

/**
 * Libera a "available" las Creator Coins cuya retención antifraude venció.
 * Programar diario (routes/console.php).
 */
class ReleaseCreatorHolds extends Command
{
    protected $signature = 'charbook:release-creator-holds';

    protected $description = 'Libera Creator Coins pendientes con retención vencida';

    public function handle(CreatorLedgerService $ledger): int
    {
        $released = $ledger->releaseMatured();
        $this->info("Transacciones liberadas: {$released}");

        return self::SUCCESS;
    }
}
