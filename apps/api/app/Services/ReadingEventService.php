<?php

namespace App\Services;

use App\Models\ReadingEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Detección de lectura real. Un capítulo abierto NO es lectura.
 * Los hitos válidos alimentan el Creator Fund y las recompensas.
 */
class ReadingEventService
{
    public const MILESTONES = ['chapter_open', 'chapter_25', 'chapter_50', 'chapter_75', 'chapter_complete'];

    /** Scroll mínimo exigido por hito. */
    private const MIN_SCROLL = [
        'chapter_open' => 0,
        'chapter_25' => 25,
        'chapter_50' => 50,
        'chapter_75' => 75,
        'chapter_complete' => 95,
    ];

    /** Fracción del tiempo mínimo del capítulo exigida por hito. */
    private const TIME_FRACTION = [
        'chapter_open' => 0,
        'chapter_25' => 0.25,
        'chapter_50' => 0.5,
        'chapter_75' => 0.75,
        'chapter_complete' => 1.0,
    ];

    public function __construct(private readonly WalletService $wallets) {}

    public function record(User $user, int $bookId, ?int $chapterId, string $milestone, int $seconds, int $scrollPct): ReadingEvent
    {
        if (! in_array($milestone, self::MILESTONES, true)) {
            throw ValidationException::withMessages(['milestone' => ['Hito inválido.']]);
        }

        $minSeconds = (int) PlatformSettings::get('reading.min_seconds_per_chapter');
        $requiredSeconds = (int) ceil($minSeconds * self::TIME_FRACTION[$milestone]);
        $requiredScroll = self::MIN_SCROLL[$milestone];

        // Hitos monotónicos: para registrar 50 debe existir 25 válido, etc.
        $order = array_flip(self::MILESTONES);
        $prevValidCount = ReadingEvent::where('user_id', $user->id)
            ->where('chapter_id', $chapterId)
            ->whereDate('day', today())
            ->where('is_valid', true)
            ->pluck('milestone')
            ->map(fn ($m) => $order[$m])
            ->max();
        $monotonic = $order[$milestone] === 0 || ($prevValidCount !== null && $order[$milestone] <= $prevValidCount + 1);

        // Cap diario de capítulos válidos (antifraude granjas)
        $dailyCap = (int) PlatformSettings::get('reading.daily_valid_chapters');
        $validToday = ReadingEvent::where('user_id', $user->id)
            ->whereDate('day', today())
            ->where('is_valid', true)
            ->distinct('chapter_id')
            ->count('chapter_id');

        $isValid = $monotonic
            && $seconds >= $requiredSeconds
            && $scrollPct >= $requiredScroll
            && $validToday < $dailyCap;

        $event = ReadingEvent::updateOrCreate(
            [
                'user_id' => $user->id,
                'chapter_id' => $chapterId,
                'milestone' => $milestone,
                'day' => today()->toDateString(),
            ],
            [
                'book_id' => $bookId,
                'seconds_accumulated' => $seconds,
                'scroll_pct' => min(100, $scrollPct),
                'is_valid' => $isValid,
            ],
        );

        // Recompensa por completar libro (último capítulo completado válido)
        if ($isValid && $milestone === 'chapter_complete') {
            $this->maybeRewardBookComplete($user, $bookId, $chapterId);
        }

        return $event;
    }

    private function maybeRewardBookComplete(User $user, int $bookId, ?int $chapterId): void
    {
        $book = \App\Models\Book::find($bookId);
        if (! $book || ! $chapterId) {
            return;
        }

        $publishedChapters = $book->chapters()->where('is_published', true)->count();
        if ($publishedChapters === 0) {
            return;
        }

        $completed = ReadingEvent::where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->where('milestone', 'chapter_complete')
            ->where('is_valid', true)
            ->distinct('chapter_id')
            ->count('chapter_id');

        if ($completed >= $publishedChapters) {
            $this->wallets->creditRewardCapped(
                $user->id,
                (int) PlatformSettings::get('rewards.book_complete'),
                'book_complete',
                "book_complete:{$user->id}:{$bookId}", // una sola vez por libro
                ['book_id' => $bookId],
            );
        }
    }
}
