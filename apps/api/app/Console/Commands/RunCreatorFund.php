<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\CreatorFundDistribution;
use App\Models\ReadingEvent;
use App\Models\RevenueEvent;
use App\Services\CreatorLedgerService;
use App\Services\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creator Fund mensual.
 *
 * Pool = % configurable del ingreso neto de Premium Plus del mes.
 * Reparto por LECTURA EFECTIVA (eventos válidos), no por visitas:
 *   chapter_complete = 3 pts · chapter_75 = 2 pts · chapter_50 = 1 pt
 * solo sobre obras participantes (in_unlimited_catalog).
 */
class RunCreatorFund extends Command
{
    protected $signature = 'charbook:creator-fund {month : YYYY-MM}';

    protected $description = 'Calcula y acredita la distribución mensual del Creator Fund';

    private const POINTS = ['chapter_complete' => 3, 'chapter_75' => 2, 'chapter_50' => 1];

    public function handle(CreatorLedgerService $ledger): int
    {
        $month = $this->argument('month');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Formato de mes inválido (usa YYYY-MM).');
            return self::FAILURE;
        }

        if (CreatorFundDistribution::where('month', $month)->where('status', 'credited')->exists()) {
            $this->warn("El fondo de {$month} ya fue acreditado.");
            return self::SUCCESS;
        }

        $from = "{$month}-01 00:00:00";
        $to = date('Y-m-t 23:59:59', strtotime($from));

        // 1. Pool: % del ingreso de Premium Plus del mes
        $plusRevenue = (float) RevenueEvent::where('category', 'subscription_plus')
            ->whereBetween('created_at', [$from, $to])
            ->sum('gross_usd');

        $fundPct = (float) PlatformSettings::get('fund.premium_plus_pct');
        $pool = round($plusRevenue * $fundPct / 100, 2);

        // 2. Puntos de lectura efectiva por creador (solo obras participantes)
        $participantBooks = Book::where('in_unlimited_catalog', true)
            ->whereNotNull('creator_id')
            ->pluck('creator_id', 'id');

        $caseSql = "case milestone when 'chapter_complete' then 3 when 'chapter_75' then 2 when 'chapter_50' then 1 else 0 end";
        $rows = ReadingEvent::whereIn('book_id', $participantBooks->keys())
            ->where('is_valid', true)
            ->whereBetween('day', [substr($from, 0, 10), substr($to, 0, 10)])
            ->selectRaw("book_id, sum({$caseSql}) as points")
            ->groupBy('book_id')
            ->get();

        $pointsByCreator = [];
        foreach ($rows as $row) {
            $creatorId = $participantBooks[$row->book_id] ?? null;
            if ($creatorId) {
                $pointsByCreator[$creatorId] = ($pointsByCreator[$creatorId] ?? 0) + (int) $row->points;
            }
        }
        $totalPoints = array_sum($pointsByCreator);

        $this->info("Mes {$month} · ingreso Plus: {$plusRevenue} USD · pool ({$fundPct}%): {$pool} USD · puntos: {$totalPoints}");

        // 3. Prorrateo y acreditación
        $coinToUsd = (float) PlatformSettings::get('creator.coin_to_usd');
        $breakdown = [];

        DB::transaction(function () use ($month, $pool, $totalPoints, $pointsByCreator, $coinToUsd, $ledger, &$breakdown) {
            foreach ($pointsByCreator as $creatorId => $points) {
                $usd = $totalPoints > 0 ? round($pool * $points / $totalPoints, 2) : 0;
                $coins = (int) floor($usd / $coinToUsd);
                $breakdown[] = ['creator_id' => $creatorId, 'points' => $points, 'usd' => $usd, 'coins' => $coins];

                if ($coins > 0) {
                    $event = RevenueEvent::create([
                        'category' => 'creator_fund',
                        'gross_usd' => 0,
                        'creator_usd' => $usd,
                        'platform_usd' => -$usd, // sale del pool de plataforma
                        'creator_id' => $creatorId,
                        'meta' => ['month' => $month, 'points' => $points],
                    ]);

                    $ledger->creditPending($creatorId, $coins, 'creator_fund', $event->id, "fund:{$month}:{$creatorId}", ['month' => $month]);
                }
            }

            CreatorFundDistribution::updateOrCreate(
                ['month' => $month],
                [
                    'pool_usd' => $pool,
                    'total_points' => $totalPoints,
                    'status' => 'credited',
                    'breakdown' => $breakdown,
                ],
            );
        });

        $this->info('Creadores beneficiados: ' . count($breakdown));

        return self::SUCCESS;
    }
}
