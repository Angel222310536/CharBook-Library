<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookView;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookViewService
{
    private const SESSION_MINUTES = 30;

    public function record(Book $book, ?User $user, ?string $visitorKey, Request $request): array
    {
        $now = now();
        $visitorHash = $user ? null : $this->visitorHash($visitorKey, $request);

        return DB::transaction(function () use ($book, $user, $visitorHash, $now) {
            $identity = ['book_id' => $book->id];
            if ($user) {
                $identity['user_id'] = $user->id;
            } else {
                $identity['visitor_hash'] = $visitorHash;
            }

            $view = BookView::where($identity)->lockForUpdate()->first();
            $uniqueCounted = false;
            $sessionCounted = false;

            if (! $view) {
                BookView::create($identity + [
                    'user_id' => $user?->id,
                    'visitor_hash' => $visitorHash,
                    'first_viewed_at' => $now,
                    'last_viewed_at' => $now,
                    'sessions_count' => 1,
                ]);

                Book::whereKey($book->id)->update([
                    'views_count' => DB::raw('views_count + 1'),
                    'total_readings_count' => DB::raw('total_readings_count + 1'),
                ]);

                $uniqueCounted = true;
                $sessionCounted = true;
            } else {
                $sessionExpired = $view->last_viewed_at
                    ? $view->last_viewed_at->lt($now->copy()->subMinutes(self::SESSION_MINUTES))
                    : true;

                $view->last_viewed_at = $now;
                if ($sessionExpired) {
                    $view->sessions_count++;
                    $sessionCounted = true;

                    Book::whereKey($book->id)->increment('total_readings_count');
                }
                $view->save();
            }

            $fresh = $book->fresh();

            return [
                'unique_counted' => $uniqueCounted,
                'session_counted' => $sessionCounted,
                'unique_readers_count' => (int) $fresh->views_count,
                'total_readings_count' => (int) $fresh->total_readings_count,
            ];
        });
    }

    private function visitorHash(?string $visitorKey, Request $request): string
    {
        $key = trim((string) $visitorKey);
        if ($key === '') {
            $key = implode('|', [
                $request->ip() ?: 'unknown-ip',
                substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        return hash_hmac('sha256', $key, (string) config('app.key'));
    }
}
