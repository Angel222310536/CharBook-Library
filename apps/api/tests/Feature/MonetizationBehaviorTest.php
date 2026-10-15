<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\CoinPackage;
use App\Models\CoinTransaction;
use App\Models\CreatorCoinTransaction;
use App\Models\CreatorFundDistribution;
use App\Models\CreatorWallet;
use App\Models\ReadingEvent;
use App\Models\RevenueEvent;
use App\Models\Subscription;
use App\Models\Unlock;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AdRewardService;
use App\Services\CreatorLedgerService;
use App\Services\PlatformSettings;
use App\Services\ReadingEventService;
use App\Services\UnlockService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonetizationBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scout.driver', null);
        Config::set('services.stripe.webhook_secret', null);

        foreach (PlatformSettings::DEFAULTS as $key => $value) {
            PlatformSettings::set($key, $value);
        }
    }

    public function test_wallet_moves_are_idempotent_and_refuse_negative_balances(): void
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);

        $first = $wallets->move($user->id, 'premium', 100, 'purchase', 'wallet-test');
        $retry = $wallets->move($user->id, 'premium', 100, 'purchase', 'wallet-test');

        $this->assertTrue($first->is($retry));
        $this->assertSame(100, Wallet::firstWhere('user_id', $user->id)->premium_balance);
        $this->assertSame(1, CoinTransaction::where('idempotency_key', 'wallet-test')->count());

        $this->expectException(ValidationException::class);
        $wallets->move($user->id, 'premium', -101, 'unlock', 'wallet-negative');
    }

    public function test_reward_caps_limit_daily_grants_and_ad_sessions(): void
    {
        $user = User::factory()->create();
        PlatformSettings::set('rewards.daily_cap', 3);
        PlatformSettings::set('rewards.weekly_cap', 50);
        PlatformSettings::set('rewards.ad_daily_cap', 1);
        PlatformSettings::set('rewards.per_ad', 2);

        $wallets = app(WalletService::class);
        $this->assertSame(2, $wallets->creditRewardCapped($user->id, 2, 'daily_login', 'daily-1'));
        $this->assertSame(1, $wallets->creditRewardCapped($user->id, 2, 'achievement', 'daily-2'));
        $this->assertSame(0, $wallets->creditRewardCapped($user->id, 2, 'achievement', 'daily-3'));

        $ads = app(AdRewardService::class);
        $session = $ads->createSession($user, 'reward_screen');
        $result = $ads->confirm($session->nonce);

        $this->assertSame(0, $result['granted']);
        $this->assertTrue($result['capped']);

        $this->expectException(ValidationException::class);
        $ads->createSession($user, 'reward_screen');
    }

    public function test_unlock_early_access_uses_only_premium_and_creates_pending_creator_earnings(): void
    {
        [$reader, $creator, $book, $chapter] = $this->paidChapterFixture([
            'coin_price' => 10,
            'early_access_until' => now()->addDay(),
        ]);

        app(WalletService::class)->move($reader->id, 'premium', 100, 'purchase', 'premium-seed');
        app(WalletService::class)->move($reader->id, 'reward', 100, 'admin_adjust', 'reward-seed');

        $result = app(UnlockService::class)->unlock(
            $reader,
            $book,
            collect([$chapter]),
            'single',
            null,
            10,
            'unlock-early',
        );

        $wallet = Wallet::firstWhere('user_id', $reader->id);
        $creatorWallet = CreatorWallet::firstWhere('user_id', $creator->id);
        $creatorTx = CreatorCoinTransaction::firstWhere('creator_id', $creator->id);

        $this->assertSame(['premium' => 10, 'reward' => 0, 'total' => 10], $result['paid']);
        $this->assertSame(90, $wallet->premium_balance);
        $this->assertSame(100, $wallet->reward_balance);
        $this->assertSame(1, Unlock::where('user_id', $reader->id)->where('chapter_id', $chapter->id)->count());
        $this->assertNotNull($creatorWallet);
        $this->assertSame($creatorTx->amount, $creatorWallet->pending_coins);
        $this->assertSame(0, $creatorWallet->available_coins);
        $this->assertSame('pending', $creatorTx->status);
        $this->assertTrue($creatorTx->available_at->greaterThan(now()));
    }

    public function test_premium_plus_grants_early_access_and_unlimited_catalog_access(): void
    {
        [$reader, , $book, $chapter] = $this->paidChapterFixture([
            'coin_price' => 20,
            'early_access_until' => now()->addDay(),
        ]);

        Subscription::create([
            'user_id' => $reader->id,
            'plan' => 'premium_plus',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);

        $earlyAccess = app(UnlockService::class)->chapterAccess($reader, $book, $chapter);
        $this->assertTrue($earlyAccess['access']);
        $this->assertSame('subscription', $earlyAccess['reason']);

        $chapter->update(['early_access_until' => null]);
        $book->update(['in_unlimited_catalog' => true]);

        $catalogAccess = app(UnlockService::class)->chapterAccess($reader, $book->fresh(), $chapter->fresh());
        $this->assertTrue($catalogAccess['access']);
        $this->assertSame('subscription', $catalogAccess['reason']);
    }

    public function test_premium_reads_paid_chapters_but_not_early_access(): void
    {
        // Política nueva (tarea G): Premium lee TODO lo de pago sin paywall, pero
        // el ACCESO ANTICIPADO es exclusivo de Premium Plus.
        [$reader, , $book, $chapter] = $this->paidChapterFixture(['coin_price' => 30]);

        Subscription::create([
            'user_id' => $reader->id,
            'plan' => 'premium',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);

        // Capítulo de pago normal → acceso por suscripción.
        $access = app(UnlockService::class)->chapterAccess($reader, $book, $chapter);
        $this->assertTrue($access['access']);
        $this->assertSame('subscription', $access['reason']);

        // El mismo capítulo en ventana de acceso anticipado → Premium NO entra.
        $chapter->update(['early_access_until' => now()->addDay()]);
        $early = app(UnlockService::class)->chapterAccess($reader->fresh(), $book->fresh(), $chapter->fresh());
        $this->assertFalse($early['access']);
        $this->assertSame('early_access', $early['reason']);
    }

    public function test_usuario_sin_plan_si_ve_paywall(): void
    {
        [$reader, , $book, $chapter] = $this->paidChapterFixture(['coin_price' => 30]);

        $access = app(UnlockService::class)->chapterAccess($reader, $book, $chapter);
        $this->assertFalse($access['access']);
        $this->assertSame('paywall', $access['reason']);
        // El paywall ofrece la espera gratis (alternativa a pagar con monedas).
        $this->assertTrue($access['wait_available']);
    }

    public function test_wait_unlock_lets_free_reader_open_chapter_after_waiting(): void
    {
        PlatformSettings::set('unlocks.wait_hours', 48);
        [$reader, , $book, $chapter] = $this->paidChapterFixture(['coin_price' => 10]);
        $service = app(UnlockService::class);

        // 1) El paywall ofrece esperar 48 h gratis.
        $a1 = $service->chapterAccess($reader, $book, $chapter);
        $this->assertFalse($a1['access']);
        $this->assertTrue($a1['wait_available']);
        $this->assertSame(48, $a1['wait_hours']);
        $this->assertNull($a1['wait_until']);

        // 2) Reservar: arranca el contador pero sigue bloqueado.
        $wait = $service->startWait($reader, $book, $chapter);
        $a2 = $service->chapterAccess($reader, $book, $chapter);
        $this->assertFalse($a2['access']);
        $this->assertNotNull($a2['wait_until']);

        // startWait es idempotente: no reinicia el contador.
        $this->assertTrue($wait->is($service->startWait($reader, $book, $chapter)));

        // 3) Pasado el tiempo: acceso gratis y permanente.
        $wait->update(['unlocks_at' => now()->subMinute()]);
        $a3 = $service->chapterAccess($reader, $book, $chapter);
        $this->assertTrue($a3['access']);
        $this->assertSame('wait', $a3['reason']);
    }

    public function test_wait_unlock_not_offered_on_early_access(): void
    {
        [$reader, , $book, $chapter] = $this->paidChapterFixture([
            'coin_price' => 10,
            'early_access_until' => now()->addDay(),
        ]);

        $access = app(UnlockService::class)->chapterAccess($reader, $book, $chapter);
        $this->assertFalse($access['access']);
        $this->assertSame('early_access', $access['reason']);
        $this->assertFalse($access['wait_available']);
    }

    public function test_wait_unlock_endpoint_starts_timer(): void
    {
        [$reader, , , $chapter] = $this->paidChapterFixture(['coin_price' => 10]);
        Sanctum::actingAs($reader);

        $this->postJson("/api/chapters/{$chapter->id}/wait-unlock")
            ->assertCreated()
            ->assertJsonStructure(['unlocks_at', 'wait_hours']);

        $this->assertDatabaseHas('chapter_wait_unlocks', [
            'user_id' => $reader->id,
            'chapter_id' => $chapter->id,
        ]);
    }

    public function test_sequential_gate_locks_chapters_after_a_locked_paid_chapter(): void
    {
        $reader = User::factory()->create();
        $author = Author::create(['name' => 'QA Seq']);
        $book = Book::create([
            'title' => 'QA Seq Book',
            'description' => 'Fixture for sequential paywall.',
            'author_id' => $author->id,
            'access_type' => 'premium',
            'language' => 'es',
            'is_published' => true,
            'work_format' => 'serie',
            'monetization' => ['free_chapters' => 0, 'packs' => [3], 'pack_discount_pct' => []],
        ]);

        // 1,2 gratis · 3 pago · 4 gratis · 5 pago · 6 gratis
        $ch = [];
        foreach ([1 => 0, 2 => 0, 3 => 10, 4 => 0, 5 => 10, 6 => 0] as $n => $p) {
            $ch[$n] = Chapter::create([
                'book_id' => $book->id, 'number' => $n, 'title' => "C{$n}", 'body' => 'x',
                'position' => $n, 'is_published' => true, 'coin_price' => $p,
            ]);
        }

        $svc = app(UnlockService::class);
        $book = $book->fresh();
        $reason = fn ($n) => $svc->chapterAccess($reader, $book, $ch[$n])['reason'];
        $can = fn ($n) => $svc->chapterAccess($reader, $book, $ch[$n])['access'];

        // Inicio: 1,2 libres; 3 es la puerta; 4,5,6 bloqueados en cadena.
        $this->assertTrue($can(1));
        $this->assertTrue($can(2));
        $this->assertFalse($can(3));
        $this->assertSame('paywall', $reason(3));
        $this->assertSame('locked_sequential', $reason(4));
        $this->assertSame('locked_sequential', $reason(6));
        $this->assertSame(3, $svc->chapterAccess($reader, $book, $ch[4])['gate_number']);

        // Pagar la puerta (cap 3): la puerta avanza al 5; el 4 (gratis) se abre solo.
        Unlock::create(['user_id' => $reader->id, 'book_id' => $book->id, 'chapter_id' => $ch[3]->id, 'source' => 'single']);
        $svc->resetAccessCache();
        $this->assertTrue($can(3));
        $this->assertTrue($can(4));
        $this->assertFalse($can(5));
        $this->assertSame('paywall', $reason(5));
        $this->assertSame('locked_sequential', $reason(6));

        // Pagar la nueva puerta (cap 5): el 6 se abre.
        Unlock::create(['user_id' => $reader->id, 'book_id' => $book->id, 'chapter_id' => $ch[5]->id, 'source' => 'single']);
        $svc->resetAccessCache();
        $this->assertTrue($can(5));
        $this->assertTrue($can(6));
    }

    public function test_subscriber_bypasses_sequential_gate(): void
    {
        $reader = User::factory()->create(['subscription_tier' => 'premium']);
        $author = Author::create(['name' => 'QA Seq2']);
        $book = Book::create([
            'title' => 'QA Seq2 Book', 'description' => '...', 'author_id' => $author->id,
            'access_type' => 'premium', 'language' => 'es', 'is_published' => true, 'work_format' => 'serie',
            'monetization' => ['free_chapters' => 0],
        ]);
        $paid = Chapter::create(['book_id' => $book->id, 'number' => 1, 'title' => 'C1', 'body' => 'x', 'position' => 1, 'is_published' => true, 'coin_price' => 10]);
        $free = Chapter::create(['book_id' => $book->id, 'number' => 2, 'title' => 'C2', 'body' => 'x', 'position' => 2, 'is_published' => true, 'coin_price' => 0]);

        $svc = app(UnlockService::class);
        $this->assertNull($svc->firstLockedGate($reader, $book->fresh()));
        $this->assertTrue($svc->chapterAccess($reader, $book->fresh(), $free)['access']);
        $this->assertTrue($svc->chapterAccess($reader, $book->fresh(), $paid)['access']);
    }

    public function test_manual_premium_plus_tier_grants_subscription_access(): void
    {
        [$reader, , $book, $chapter] = $this->paidChapterFixture([
            'coin_price' => 20,
            'early_access_until' => now()->addDay(),
        ]);
        $reader->forceFill(['subscription_tier' => 'premium_plus'])->save();

        $earlyAccess = app(UnlockService::class)->chapterAccess($reader->fresh(), $book, $chapter);

        $this->assertTrue($earlyAccess['access']);
        $this->assertSame('subscription', $earlyAccess['reason']);
    }

    public function test_reading_events_enforce_monotonic_progress_and_reward_book_completion_once(): void
    {
        [$reader, , $book, $chapter] = $this->paidChapterFixture(['coin_price' => 0]);
        $skippedChapter = Chapter::create([
            'book_id' => $book->id,
            'number' => 2,
            'title' => 'Skipped QA Chapter',
            'body' => 'Body',
            'position' => 2,
            'is_published' => false,
            'coin_price' => 0,
        ]);
        PlatformSettings::set('reading.min_seconds_per_chapter', 40);
        PlatformSettings::set('rewards.book_complete', 2);

        $service = app(ReadingEventService::class);

        $skipped = $service->record($reader, $book->id, $skippedChapter->id, 'chapter_complete', 40, 100);
        $this->assertFalse($skipped->is_valid);

        $this->assertTrue($service->record($reader, $book->id, $chapter->id, 'chapter_open', 0, 0)->is_valid);
        $this->assertTrue($service->record($reader, $book->id, $chapter->id, 'chapter_25', 10, 25)->is_valid);
        $this->assertTrue($service->record($reader, $book->id, $chapter->id, 'chapter_50', 20, 50)->is_valid);
        $this->assertTrue($service->record($reader, $book->id, $chapter->id, 'chapter_75', 30, 75)->is_valid);
        $this->assertTrue($service->record($reader, $book->id, $chapter->id, 'chapter_complete', 40, 95)->is_valid);

        $wallet = Wallet::firstWhere('user_id', $reader->id);
        $this->assertSame(2, $wallet->reward_balance);
        $this->assertSame(1, CoinTransaction::where('type', 'book_complete')->count());
    }

    public function test_creator_earnings_release_from_pending_to_available(): void
    {
        $creator = User::factory()->create();
        PlatformSettings::set('payouts.hold_days', 1);

        $ledger = app(CreatorLedgerService::class);
        $tx = $ledger->creditPending($creator->id, 25, 'unlock', null, 'creator-pending');
        $tx->update(['available_at' => now()->subMinute()]);

        $this->assertSame(1, $ledger->releaseMatured());

        $wallet = CreatorWallet::firstWhere('user_id', $creator->id);
        $this->assertSame(0, $wallet->pending_coins);
        $this->assertSame(25, $wallet->available_coins);
        $this->assertSame('available', $tx->fresh()->status);
    }

    public function test_stripe_coin_package_webhook_is_idempotent(): void
    {
        $reader = User::factory()->create();
        $package = CoinPackage::create([
            'name' => 'QA Pack',
            'coins' => 100,
            'bonus_coins' => 20,
            'price_usd' => 1.99,
            'is_active' => true,
            'sort' => 1,
        ]);

        $payload = [
            'id' => 'evt_qa_coin_package',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_qa_coin_package',
                    'object' => 'checkout.session',
                    'amount_total' => 199,
                    'metadata' => [
                        'kind' => 'coin_package',
                        'charbook_user_id' => (string) $reader->id,
                        'package_id' => (string) $package->id,
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();
        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $this->assertSame(120, Wallet::firstWhere('user_id', $reader->id)->premium_balance);
        $this->assertSame(1, RevenueEvent::where('stripe_event_id', 'evt_qa_coin_package')->count());
        $this->assertSame(1, CoinTransaction::where('idempotency_key', 'stripe:cs_qa_coin_package')->count());
    }

    public function test_creator_fund_credits_only_valid_reading_points_for_participating_books(): void
    {
        [$reader, $creator, $book, $chapter] = $this->paidChapterFixture([
            'coin_price' => 0,
            'in_unlimited_catalog' => true,
        ]);
        $month = now()->format('Y-m');

        RevenueEvent::create([
            'category' => 'subscription_plus',
            'gross_usd' => 100,
            'platform_usd' => 100,
            'user_id' => $reader->id,
        ]);
        ReadingEvent::create([
            'user_id' => $reader->id,
            'book_id' => $book->id,
            'chapter_id' => $chapter->id,
            'milestone' => 'chapter_complete',
            'seconds_accumulated' => 60,
            'scroll_pct' => 100,
            'is_valid' => true,
            'day' => today()->toDateString(),
        ]);
        ReadingEvent::create([
            'user_id' => $reader->id,
            'book_id' => $book->id,
            'chapter_id' => $chapter->id,
            'milestone' => 'chapter_75',
            'seconds_accumulated' => 5,
            'scroll_pct' => 75,
            'is_valid' => false,
            'day' => today()->toDateString(),
        ]);

        $this->artisan('charbook:creator-fund', ['month' => $month])->assertSuccessful();

        $distribution = CreatorFundDistribution::firstWhere('month', $month);
        $wallet = CreatorWallet::firstWhere('user_id', $creator->id);

        $this->assertSame(3, $distribution->total_points);
        $this->assertSame('credited', $distribution->status);
        $this->assertSame(5000, $wallet->pending_coins);
        $this->assertSame(1, CreatorCoinTransaction::where('idempotency_key', "fund:{$month}:{$creator->id}")->count());
    }

    public function test_secure_file_routes_return_json_401_and_require_access(): void
    {
        [, , $book] = $this->paidChapterFixture(['coin_price' => 10]);
        $book->update([
            'access_type' => 'premium',
            'file_path' => 'books/protected.epub',
            'file_type' => 'epub',
        ]);

        $this->getJson("/api/books/{$book->slug}/secure-file")
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/books/{$book->slug}/secure-file")
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    private function paidChapterFixture(array $chapterOverrides = []): array
    {
        $reader = User::factory()->create();
        $creator = User::factory()->create(['creator_status' => 'approved']);
        $author = Author::create(['name' => 'QA Author']);

        $book = Book::create([
            'title' => 'QA Monetization Book',
            'description' => 'Fixture book for monetization tests.',
            'author_id' => $author->id,
            'creator_id' => $creator->id,
            'access_type' => 'premium',
            'language' => 'es',
            'is_published' => true,
            'work_format' => 'serie',
            'monetization' => [
                'free_chapters' => 0,
                'packs' => [3, 5, 10],
                'pack_discount_pct' => ['3' => 5],
            ],
            'in_unlimited_catalog' => (bool) ($chapterOverrides['in_unlimited_catalog'] ?? false),
        ]);

        $chapter = Chapter::create(array_merge([
            'book_id' => $book->id,
            'number' => 1,
            'title' => 'QA Chapter',
            'body' => 'Body',
            'position' => 1,
            'is_published' => true,
            'coin_price' => 10,
            'early_access_until' => null,
        ], array_diff_key($chapterOverrides, ['in_unlimited_catalog' => true])));

        return [$reader, $creator, $book->fresh(), $chapter->fresh()];
    }
}
