<?php

use App\Http\Controllers\Api\ActivityReactionController;
use App\Http\Controllers\Api\Admin\CreatorApplicationController as AdminCreatorApplicationController;
use App\Http\Controllers\Api\Me\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\PasskeyController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\AdminBroadcastController;
use App\Http\Controllers\Api\Admin\AdminUploadedWorksController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\AffiliateLinkController;
use App\Http\Controllers\Api\AnthologyController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CreatorApplicationController;
use App\Http\Controllers\Api\CreatorsDirectoryController;
use App\Http\Controllers\Api\CreatorWidgetController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\ChapterImportController;
use App\Http\Controllers\Api\ChaptersController;
use App\Http\Controllers\Api\ComicPagesController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\MessagesController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CreatorMonetizationController;
use App\Http\Controllers\Api\MonetizationController;
use App\Http\Controllers\Api\SecureFileController;
use App\Http\Controllers\Api\Admin\MonetizationAdminController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\MyWorksController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReaderSearchController;
use App\Http\Controllers\Api\ReadingStatusController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// =========================================================
// ===== AUTH: registro / login / password / 2FA (public) =====
// =========================================================
Route::prefix('auth')->middleware('throttle:api')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    // 2FA durante login
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'sendChallengeCode']);
    Route::post('/two-factor/verify', [TwoFactorController::class, 'verifyChallenge']);
    Route::post('/passkey/verify', [PasskeyController::class, 'verify']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/email/send-verification', [AuthController::class, 'sendEmailVerification']);
    Route::post('/auth/email/verify', [AuthController::class, 'verifyEmail']);
});

// =========================================================
// ===== ME: cuenta, avatar, preferencias, 2FA, passkeys =====
// =========================================================
Route::middleware('auth:sanctum')->group(function () {
    // Cuenta
    Route::patch('/me/account', [AccountController::class, 'update']);
    Route::patch('/me/password', [AccountController::class, 'changePassword']);
    Route::post('/me/avatar', [AccountController::class, 'uploadAvatar']);
    Route::patch('/me/preferences', [AccountController::class, 'updatePreferences']);
    // Preferencias exclusivas de Premium Plus (protegidas por entitlement).
    Route::patch('/me/plus/voice', [\App\Http\Controllers\Api\PlusController::class, 'setVoice'])
        ->middleware('entitlement:ai_voices');
    Route::patch('/me/plus/profile', [\App\Http\Controllers\Api\PlusController::class, 'setProfile'])
        ->middleware('entitlement:advanced_profile');
    Route::get('/me/subscription', [AccountController::class, 'subscription']);
    Route::get('/me/sessions', [AccountController::class, 'sessions']);
    Route::delete('/me/sessions/{id}', [AccountController::class, 'revokeSession']);
    Route::delete('/me/account', [AccountController::class, 'deleteAccount']);

    // 2FA
    Route::prefix('me/two-factor')->group(function () {
        Route::post('/totp/setup', [TwoFactorController::class, 'totpSetup']);
        Route::post('/totp/confirm', [TwoFactorController::class, 'totpConfirm']);
        Route::post('/email/challenge', [TwoFactorController::class, 'sendAccountEmailCode']);
        Route::post('/email/enable', [TwoFactorController::class, 'enableEmail']);
        Route::post('/email/confirm', [TwoFactorController::class, 'confirmEmail']);
        Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
    });

    // Passkeys
    Route::get('/me/passkeys', [PasskeyController::class, 'index']);
    Route::post('/me/passkeys/options', [PasskeyController::class, 'registerOptions']);
    Route::post('/me/passkeys', [PasskeyController::class, 'store']);
    Route::delete('/me/passkeys/{passkey:id}', [PasskeyController::class, 'destroy']);
});

// ===== Health check (sin rate limit) =====
Route::get('/health', function () {
    try {
        \DB::connection()->getPdo();
        $db = 'ok';
    } catch (\Throwable) {
        $db = 'fail';
    }
    return response()->json([
        'ok' => true,
        'name' => 'CharBook Library API',
        'version' => 'v2',
        'db' => $db,
        'time' => now()->toIso8601String(),
    ]);
});

// =========================================================
// ============ Rutas públicas (con rate limit 'api') ============
// =========================================================

Route::middleware('throttle:api')->group(function () {

    Route::prefix('books')->group(function () {
        Route::get('/', [BookController::class, 'index']);
        Route::get('/featured', [BookController::class, 'featured']);
        Route::get('/recent', [BookController::class, 'recent']);
        Route::get('/popular', [BookController::class, 'popular']);
        Route::get('/{book:slug}', [BookController::class, 'show']);
        Route::post('/{book:slug}/view', [BookController::class, 'recordView']);
        // file-proxy es público (los iframes y <a download> no envían Bearer).
        // El gate de "verifica tu correo" lo aplica el frontend en <DownloadButton/>.
        Route::get('/{book:slug}/file-proxy', [BookController::class, 'fileProxy']);
        Route::get('/{book:slug}/reviews', [ReviewController::class, 'index']);
        Route::get('/{book:slug}/comments', [CommentController::class, 'bookIndex']);
        Route::get('/{book:slug}/affiliate-links', [AffiliateLinkController::class, 'indexForBook']);

        // ===== Lectura pública (capítulos / cómic) =====
        Route::get('/{book:slug}/chapters', [ReadingController::class, 'chapters']);
        Route::get('/{book:slug}/chapters/{number}', [ReadingController::class, 'chapter']);
        Route::get('/{book:slug}/pages', [ReadingController::class, 'pages']);
        // Descarga en un solo archivo (EPUB) — solo si el creador la habilitó.
        Route::get('/{book:slug}/download', [ReadingController::class, 'download']);
    });

    // Archivo (PDF) de capítulo — streaming server-side (R2) para el lector.
    Route::get('/chapters/{chapter:id}/file-proxy', [ReadingController::class, 'chapterFileProxy']);

    // Comentarios estilo WEBTOON (lectura pública)
    Route::get('/chapters/{chapter:id}/comments', [CommentController::class, 'chapterIndex']);
    Route::get('/comments/{comment:id}/replies', [CommentController::class, 'replies']);

    // Search instantáneo: rate limit más permisivo
    Route::middleware('throttle:search')->group(function () {
        Route::get('/books/search-instant', [BookController::class, 'searchInstant']);
    });

    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/hierarchical', [CategoryController::class, 'hierarchical']);
        Route::get('/{category:slug}', [CategoryController::class, 'show']);
    });

    Route::prefix('authors')->group(function () {
        Route::get('/', [AuthorController::class, 'index']);
        Route::get('/{author:slug}', [AuthorController::class, 'show']);
    });

    // Perfiles públicos "Atelier" + grafo de seguidores (lectura).
    // El espectador se resuelve vía guard sanctum (Bearer) dentro de los resources.
    Route::prefix('users')->group(function () {
        // OJO: la búsqueda va ANTES del comodín {username} para no capturarla.
        Route::get('/search', ReaderSearchController::class);
        Route::get('/{user:username}', [ProfileController::class, 'show']);
        Route::get('/{user:username}/followers', [FollowController::class, 'followers']);
        Route::get('/{user:username}/following', [FollowController::class, 'following']);
        Route::get('/{user:username}/anthologies', [AnthologyController::class, 'forUser']);
    });

    // Directorio público de creadores (sin auth, lectura)
    Route::get('/creators', [CreatorsDirectoryController::class, 'index']);
    Route::get('/creators/books', [CreatorsDirectoryController::class, 'books']);
    Route::get('/creators/categories', [CreatorsDirectoryController::class, 'categories']);

    // Antologías públicas (detalle por slug).
    Route::get('/anthologies/{anthology:slug}', [AnthologyController::class, 'show']);

    // Actividad concreta (enlace compartido). Espectador opcional vía sanctum.
    Route::get('/activities/{activity}', [FeedController::class, 'show']);
    Route::get('/activities/{activity}/comments', [ActivityReactionController::class, 'comments']);

    // Idiomas disponibles (para el selector global)
    Route::get('/languages', fn() => response()->json([
        'data' => Language::active()->get(),
    ]));
});

// Click-tracking de afiliados (sin rate limit estricto, pero throttle 'api')
Route::middleware('throttle:api')->group(function () {
    Route::get('/affiliate-links/{link}/go', [AffiliateLinkController::class, 'track']);
});

// =========================================================
// ============ Usuario autenticado ============
// =========================================================

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        $roles = $user->getRoleNames();
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'avatar' => $user->avatar,
            'bio' => $user->bio,
            'is_public_profile' => $user->is_public_profile,
            // v3
            'locale' => $user->locale ?? 'es',
            'trust_score' => $user->trust_score ?? 25,
            'creator_status' => $user->creator_status ?? 'none',
            'roles' => $roles,
            'is_admin' => $user->hasAnyRole(['admin', 'super_admin']),
            'is_super_admin' => $user->hasRole('super_admin'),
            'is_moderator' => $user->hasAnyRole(['moderator', 'admin', 'super_admin']),
            'is_creator' => $user->creator_status === 'approved' && $user->hasRole('creator'),
            'creator_tier' => $user->creator_tier ?? 'none',
            'creator_level' => $user->creator_level,
            // auth / 2FA / preferencias / suscripción
            'google_id' => $user->google_id,
            'is_email_verified' => (bool) $user->email_verified_at,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'two_factor_methods' => $user->two_factor_methods ?? [],
            'preferences' => $user->preferences ?? null,
            'subscription_tier' => $user->subscription_tier ?? 'free',
            // Plan efectivo + entitlements (qué puede usar este plan).
            'plan' => \App\Services\Entitlements::plan($user),
            'is_premium' => \App\Services\Entitlements::isPremium($user),
            'is_premium_plus' => \App\Services\Entitlements::isPremiumPlus($user),
            'entitlements' => \App\Services\Entitlements::map($user)['features'],
        ]);
    });

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    });

    // ===== Mutaciones del usuario (rate limit 'user-mutations') =====
    Route::middleware('throttle:user-mutations')->group(function () {
        // Toggle favorito
        Route::post('/books/{book:slug}/favorite', [FavoriteController::class, 'toggle']);
        // Reviews
        Route::post('/books/{book:slug}/reviews', [ReviewController::class, 'upsert']);
        Route::delete('/books/{book:slug}/reviews/me', [ReviewController::class, 'destroyMine']);

        // Seguir / dejar de seguir lectores
        Route::post('/users/{user:username}/follow', [FollowController::class, 'toggle']);

        // Solicitudes de follow (perfiles privados): aceptar / rechazar
        Route::post('/me/follow-requests/{followRequest}/accept', [FollowController::class, 'accept']);
        Route::post('/me/follow-requests/{followRequest}/reject', [FollowController::class, 'reject']);

        // Editar mi perfil (username, bio, visibilidad)
        Route::patch('/me/profile', [ProfileController::class, 'updateMine']);

        // Antologías (curador = dueño)
        Route::post('/anthologies', [AnthologyController::class, 'store']);
        Route::patch('/anthologies/{anthology:slug}', [AnthologyController::class, 'update']);
        Route::delete('/anthologies/{anthology:slug}', [AnthologyController::class, 'destroy']);
        Route::post('/anthologies/{anthology:slug}/books', [AnthologyController::class, 'addBook']);
        Route::delete('/anthologies/{anthology:slug}/books/{book:slug}', [AnthologyController::class, 'removeBook']);

        // Estado de lectura (estantes)
        Route::put('/books/{book:slug}/status', [ReadingStatusController::class, 'set']);
        Route::delete('/books/{book:slug}/status', [ReadingStatusController::class, 'remove']);

        // Reacciones a actividades del feed
        Route::post('/activities/{activity}/like', [ActivityReactionController::class, 'toggleLike']);
        Route::post('/activities/{activity}/share', [ActivityReactionController::class, 'toggleShare']);
        Route::post('/activities/{activity}/comments', [ActivityReactionController::class, 'storeComment']);
        Route::delete('/activities/{activity}/comments/{comment}', [ActivityReactionController::class, 'destroyComment']);

        // Comentarios estilo WEBTOON (escribir / responder / like / borrar)
        Route::post('/books/{book:slug}/comments', [CommentController::class, 'bookStore']);
        Route::post('/chapters/{chapter:id}/comments', [CommentController::class, 'chapterStore']);
        Route::delete('/comments/{comment:id}', [CommentController::class, 'destroy']);
        Route::post('/comments/{comment:id}/like', [CommentController::class, 'like']);
        Route::delete('/comments/{comment:id}/like', [CommentController::class, 'unlike']);
    });

    // Listados de mis cosas
    Route::middleware('throttle:api')->group(function () {
        Route::get('/me/favorites', [FavoriteController::class, 'mine']);
        Route::get('/me/reviews', [ReviewController::class, 'mine']);
        Route::get('/me/anthologies', [AnthologyController::class, 'mine']);
        Route::get('/me/shelf', [ReadingStatusController::class, 'mine']);

        // Feed de actividad (gente que sigo / descubrir)
        Route::get('/feed', [FeedController::class, 'index']);

        // ===== v3: Solicitud de Creador (user-facing) =====
        Route::get('/me/can-apply-creator', [CreatorApplicationController::class, 'canApply']);
        Route::get('/me/creator-application', [CreatorApplicationController::class, 'mine']);

        // Solicitudes de follow entrantes (perfiles privados)
        Route::get('/me/follow-requests', [FollowController::class, 'incoming']);
        Route::get('/me/follow-requests/count', [FollowController::class, 'incomingCount']);

        // ===== Notificaciones =====
        Route::get('/me/notifications', [NotificationsController::class, 'index']);
        Route::get('/me/notifications/unread-count', [NotificationsController::class, 'unreadCount']);
        Route::get('/me/notifications/activity', [NotificationsController::class, 'activity']);
        Route::patch('/me/notifications/{notification:id}/read', [NotificationsController::class, 'markRead']);
        Route::post('/me/notifications/read-all', [NotificationsController::class, 'readAll']);
        Route::delete('/me/notifications/{notification:id}', [NotificationsController::class, 'destroy']);

        // ===== Mensajes =====
        Route::get('/me/messages', [MessagesController::class, 'index']);
        Route::get('/me/messages/counts', [MessagesController::class, 'counts']);
        Route::post('/me/messages/read-all', [MessagesController::class, 'readAll']);
        Route::get('/me/messages/{message:id}', [MessagesController::class, 'show']);
        Route::patch('/me/messages/{message:id}', [MessagesController::class, 'update']);
        Route::delete('/me/messages/{message:id}', [MessagesController::class, 'destroy']);

        // Widgets del perfil de creador (lectura — el creador ve sus propios)
        Route::get('/me/widgets', [CreatorWidgetController::class, 'mine']);

        // Mis obras (modelo abierto: cualquier user autenticado puede ser creator)
        Route::get('/me/works', [MyWorksController::class, 'index']);
        Route::get('/me/works/{work:id}', [MyWorksController::class, 'show']);

        // Estado/preview de una importación (polling — throttle de lectura).
        Route::get('/me/works/{work:id}/imports/{import:id}', [ChapterImportController::class, 'show']);
    });

    // Mutaciones de solicitud de creador (más estricto)
    Route::middleware('throttle:user-mutations')->group(function () {
        Route::post('/me/creator-application', [CreatorApplicationController::class, 'store']);
        Route::patch('/me/creator-application', [CreatorApplicationController::class, 'update']);

        // Reportes (cualquier user autenticado puede reportar)
        Route::post('/reports', [ReportController::class, 'store']);

        // CRUD de widgets de creador (solo aprobados — el controller valida)
        Route::post('/me/widgets', [CreatorWidgetController::class, 'store']);
        Route::patch('/me/widgets/{widget}', [CreatorWidgetController::class, 'update']);
        Route::delete('/me/widgets/{widget}', [CreatorWidgetController::class, 'destroy']);

        // CRUD de mis obras (requiere correo verificado para PUBLICAR contenido)
        Route::post('/me/works', [MyWorksController::class, 'store'])->middleware('verified.email');
        Route::patch('/me/works/{work:id}', [MyWorksController::class, 'update']);
        Route::delete('/me/works/{work:id}', [MyWorksController::class, 'destroy']);

        // Archivo principal (libro_completo)
        Route::post('/me/works/{work:id}/file', [MyWorksController::class, 'uploadFile']);
        Route::delete('/me/works/{work:id}/file', [MyWorksController::class, 'removeFile']);

        // Importador de libros → capítulos (EPUB/DOCX/TXT). Sistema aislado.
        Route::post('/me/works/{work:id}/imports', [ChapterImportController::class, 'store']);
        Route::post('/me/imports/{import:id}/confirm', [ChapterImportController::class, 'confirm']);
        Route::delete('/me/imports/{import:id}', [ChapterImportController::class, 'destroy']);

        // Capítulos (serie)
        Route::get('/me/works/{work:id}/chapters', [ChaptersController::class, 'index']);
        Route::post('/me/works/{work:id}/chapters', [ChaptersController::class, 'store']);
        Route::post('/me/works/{work:id}/chapters/reorder', [ChaptersController::class, 'reorder']);
        Route::patch('/me/chapters/{chapter:id}', [ChaptersController::class, 'update']);
        Route::delete('/me/chapters/{chapter:id}', [ChaptersController::class, 'destroy']);
        Route::post('/me/chapters/{chapter:id}/duplicate', [ChaptersController::class, 'duplicate']);
        Route::post('/me/chapters/{chapter:id}/file', [ChaptersController::class, 'uploadFile']);
        Route::post('/me/chapters/{chapter:id}/cover', [ChaptersController::class, 'uploadCover']);

        // Páginas de cómic — a nivel de obra (cómic continuo)
        Route::get('/me/works/{work:id}/pages', [ComicPagesController::class, 'index']);
        Route::post('/me/works/{work:id}/pages', [ComicPagesController::class, 'store']);
        Route::post('/me/works/{work:id}/pages/import', [ComicPagesController::class, 'import']);
        Route::post('/me/works/{work:id}/pages/reorder', [ComicPagesController::class, 'reorder']);
        Route::delete('/me/pages/{page:id}', [ComicPagesController::class, 'destroy']);

        // Páginas de cómic — por capítulo (serie webtoon) + autodetección
        Route::post('/me/works/{work:id}/chapters/import-comic', [ComicPagesController::class, 'importComicSeries']);
        Route::get('/me/chapters/{chapter:id}/pages', [ComicPagesController::class, 'chapterIndex']);
        Route::post('/me/chapters/{chapter:id}/pages', [ComicPagesController::class, 'chapterStore']);
        Route::post('/me/chapters/{chapter:id}/pages/import', [ComicPagesController::class, 'chapterImport']);
        Route::post('/me/chapters/{chapter:id}/pages/reorder', [ComicPagesController::class, 'chapterReorder']);
    });
});

// =========================================================
// ============ Monetización (lector + creador) ============
// =========================================================

// Webhook Stripe: SIN auth (firma verificada dentro). Stripe reintenta en 5xx.
Route::post('/webhooks/stripe', [BillingController::class, 'webhook']);

// Callback SSV de anuncios recompensados (servidor del proveedor → nuestro servidor)
Route::get('/ads/ssv-callback', [MonetizationController::class, 'adSsvCallback'])->middleware('throttle:api');

// Fallback dev para contenido protegido en disco local: URL firmada temporal de Laravel
Route::get('/books/{book:slug}/file-proxy-signed', [BookController::class, 'fileProxy'])
    ->name('books.file-proxy.signed')
    ->middleware('signed');

// Público (espectador opcional vía sanctum): paquetes y mapa de paywall del libro
Route::middleware('throttle:api')->group(function () {
    Route::get('/coin-packages', [MonetizationController::class, 'packages']);
    Route::get('/books/{book:slug}/access', [MonetizationController::class, 'bookAccess']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:api')->group(function () {
        Route::get('/wallet', [MonetizationController::class, 'wallet']);
        Route::get('/wallet/transactions', [MonetizationController::class, 'transactions']);
        Route::get('/me/unlocks', [MonetizationController::class, 'myUnlocks']);
        Route::get('/me/billing/subscription', [BillingController::class, 'subscription']);
        Route::post('/reading-events', [MonetizationController::class, 'readingEvent']);
        Route::get('/books/{book:slug}/secure-file', [SecureFileController::class, 'bookFile']);
    });

    // Mutaciones económicas (throttle estricto)
    Route::middleware('throttle:user-mutations')->group(function () {
        Route::post('/billing/checkout', [BillingController::class, 'checkout']);
        Route::post('/billing/portal', [BillingController::class, 'portal']);
        Route::post('/chapters/{chapter}/unlock', [MonetizationController::class, 'unlockChapter']);
        Route::post('/chapters/{chapter}/wait-unlock', [MonetizationController::class, 'startWaitUnlock']);
        Route::post('/books/{book:slug}/unlock-pack', [MonetizationController::class, 'unlockPack']);
        Route::post('/rewards/daily-login', [MonetizationController::class, 'dailyLogin']);
        Route::post('/ads/session', [MonetizationController::class, 'adSession']);
    });

    // Panel financiero del creador
    Route::prefix('me/creator')->middleware('throttle:api')->group(function () {
        Route::get('/wallet', [CreatorMonetizationController::class, 'wallet']);
        Route::get('/earnings', [CreatorMonetizationController::class, 'earnings']);
        Route::get('/transactions', [CreatorMonetizationController::class, 'transactions']);
        Route::get('/connect/status', [CreatorMonetizationController::class, 'connectStatus']);
        Route::get('/payouts', [CreatorMonetizationController::class, 'payouts']);
    });
    Route::prefix('me/creator')->middleware('throttle:user-mutations')->group(function () {
        Route::post('/connect', [CreatorMonetizationController::class, 'connect']);
        Route::post('/payouts', [CreatorMonetizationController::class, 'requestPayout']);
    });
    Route::patch('/me/works/{work:id}/monetization', [CreatorMonetizationController::class, 'updateWorkMonetization'])
        ->middleware('throttle:user-mutations');
});

// =========================================================
// ============ Rutas Admin ============
// =========================================================

Route::middleware(['auth:sanctum', 'admin', 'throttle:api'])->prefix('admin')->group(function () {

    Route::get('/stats', function () {
        return response()->json([
            'books_total' => \App\Models\Book::count(),
            'books_published' => \App\Models\Book::where('is_published', true)->count(),
            'books_free' => \App\Models\Book::where('access_type', 'free')->count(),
            'books_premium' => \App\Models\Book::where('access_type', 'premium')->count(),
            'authors_total' => \App\Models\Author::count(),
            'categories_total' => \App\Models\Category::count(),
            'users_total' => \App\Models\User::count(),
            'total_views' => \App\Models\Book::sum('views_count'),
            'total_readings' => \App\Models\Book::sum('total_readings_count'),
            'total_downloads' => \App\Models\Book::sum('downloads_count'),
        ]);
    });

    Route::get('/books', [BookController::class, 'adminIndex']);

    // Lookup metadata externa: rate limit MUY restrictivo (llama APIs externas)
    Route::middleware('throttle:lookup')->post(
        '/books/lookup',
        \App\Http\Controllers\Api\Admin\BookLookupController::class
    );

    Route::apiResource('books', BookController::class)
        ->except(['index', 'show'])
        ->parameters(['books' => 'book:slug']);

    Route::apiResource('authors', AuthorController::class)
        ->except(['index', 'show'])
        ->parameters(['authors' => 'author:slug']);

    Route::apiResource('categories', CategoryController::class)
        ->except(['index', 'show'])
        ->parameters(['categories' => 'category:slug']);

    Route::get('/books/{book:slug}/affiliate-links', [AffiliateLinkController::class, 'adminIndex']);
    Route::post('/books/{book:slug}/affiliate-links', [AffiliateLinkController::class, 'store']);
    Route::patch('/affiliate-links/{link}', [AffiliateLinkController::class, 'update']);
    Route::delete('/affiliate-links/{link}', [AffiliateLinkController::class, 'destroy']);

    // ===== Broadcast admin (notificaciones + mensajes masivos) =====
    Route::post('/broadcast/notification', [AdminBroadcastController::class, 'notification']);
    Route::post('/broadcast/message', [AdminBroadcastController::class, 'message']);
    Route::post('/messages/to-user', [AdminBroadcastController::class, 'messageToUser']);
    Route::get('/users/search', [AdminBroadcastController::class, 'searchUsers']);

    // ===== Obras subidas por creadores (moderación) =====
    Route::get('/uploaded-works', [AdminUploadedWorksController::class, 'index']);
    Route::get('/uploaded-works/{work:id}', [AdminUploadedWorksController::class, 'show']);
    Route::patch('/uploaded-works/{work:id}/moderation', [AdminUploadedWorksController::class, 'moderate']);
    Route::patch('/uploaded-works/{work:id}', [AdminUploadedWorksController::class, 'update']);
    Route::delete('/uploaded-works/{work:id}', [AdminUploadedWorksController::class, 'destroy']);

    // ===== v3: Gestión de usuarios + roles =====
    Route::get('/users', [UserAdminController::class, 'index']);
    Route::get('/users/{user}', [UserAdminController::class, 'show']);
    Route::patch('/users/{user}/roles', [UserAdminController::class, 'updateRoles']);
    Route::patch('/users/{user}/creator-tier', [UserAdminController::class, 'updateCreatorTier']);
    Route::patch('/users/{user}/membership', [UserAdminController::class, 'updateMembership']);
    Route::post('/users/{user}/coins', [UserAdminController::class, 'grantCoins'])
        ->middleware('throttle:user-mutations');

    // ===== Monetización: parámetros económicos editables sin deploy =====
    Route::get('/monetization/settings', [MonetizationAdminController::class, 'settings']);
    Route::put('/monetization/settings', [MonetizationAdminController::class, 'updateSettings']);

    Route::get('/coin-packages', [MonetizationAdminController::class, 'packages']);
    Route::post('/coin-packages', [MonetizationAdminController::class, 'storePackage']);
    Route::patch('/coin-packages/{package}', [MonetizationAdminController::class, 'updatePackage']);
    Route::delete('/coin-packages/{package}', [MonetizationAdminController::class, 'destroyPackage']);

    Route::get('/finance/overview', [MonetizationAdminController::class, 'financeOverview']);
    Route::get('/payouts', [MonetizationAdminController::class, 'payoutsIndex']);
    Route::patch('/payouts/{payout}', [MonetizationAdminController::class, 'payoutAction']);
    Route::post('/creator-fund/run', [MonetizationAdminController::class, 'runCreatorFund']);
});

// =========================================================
// ============ Rutas Staff (admin + super_admin + moderator) ============
// =========================================================
// Estas rutas las pueden usar TANTO admin como moderator, no solo admin.
// Por eso van en un grupo separado con el middleware 'staff'.

Route::middleware(['auth:sanctum', 'staff', 'throttle:api'])->prefix('admin')->group(function () {
    // ===== v3: Solicitudes de Creador =====
    Route::get('/creator-applications', [AdminCreatorApplicationController::class, 'index']);
    Route::get('/creator-applications/{creatorApplication}', [AdminCreatorApplicationController::class, 'show']);
    Route::post('/creator-applications/{creatorApplication}/score', [AdminCreatorApplicationController::class, 'score']);
    Route::post('/creator-applications/{creatorApplication}/approve', [AdminCreatorApplicationController::class, 'approve']);
    Route::post('/creator-applications/{creatorApplication}/reject', [AdminCreatorApplicationController::class, 'reject']);
    Route::post('/creator-applications/{creatorApplication}/request-changes', [AdminCreatorApplicationController::class, 'requestChanges']);

    // ===== v3: Reportes =====
    Route::get('/reports', [AdminReportController::class, 'index']);
    Route::get('/reports/{report}', [AdminReportController::class, 'show']);
    Route::patch('/reports/{report}/resolve', [AdminReportController::class, 'resolve']);
    Route::patch('/reports/{report}/dismiss', [AdminReportController::class, 'dismiss']);

    // ===== v3: Moderación de reseñas (staff borra cualquiera) =====
    Route::delete('/reviews/{review}', [ReviewController::class, 'adminDestroy']);
});

// =========================================================
// ============ OAuth Google (rate limit 'auth') ============
// =========================================================
// Nota: el flow OAuth pasa por routes/web.php → /auth/google
// No necesita throttle aquí porque Google ya impone su propio rate limit.
