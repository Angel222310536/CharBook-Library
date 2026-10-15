<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'google_id',
        'avatar',
        'bio',
        'is_public_profile',
        // v3
        'locale',
        'trust_score',
        'creator_status',
        'creator_tier',
        // auth / seguridad / preferencias
        'creator_level',
        'stripe_customer_id',
        'stripe_account_id',
        'two_factor_enabled',
        'two_factor_methods',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'preferences',
        'subscription_tier',
        'subscription_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_public_profile' => 'boolean',
            'trust_score' => 'integer',
            'two_factor_enabled' => 'boolean',
            'two_factor_methods' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'preferences' => 'array',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /** Solicitud activa del usuario (pending o changes_requested), o null. */
    public function activeCreatorApplication()
    {
        return $this->hasOne(CreatorApplication::class)
            ->whereIn('status', CreatorApplication::ACTIVE_STATUSES)
            ->latest('submitted_at');
    }

    /** Historial completo de solicitudes. */
    public function creatorApplications(): HasMany
    {
        return $this->hasMany(CreatorApplication::class)->latest('submitted_at');
    }

    /** Reportes que ESTE usuario ha hecho. */
    public function reportsMade(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    /** Es un creador activo (tiene rol creator + status approved). */
    public function isCreator(): bool
    {
        return $this->creator_status === 'approved' && $this->hasRole('creator');
    }

    /**
     * Nivel económico del creador (fuente del reparto de ingresos):
     * featured > verified > basic > none.
     *
     * Unificación creator_level ↔ creator_tier:
     *  - Si un admin fijó EXPLÍCITAMENTE la columna `creator_level`
     *    (basic/verified/featured), ese valor manda → control económico claro
     *    desde el panel admin.
     *  - Si no hay override (null/'none'), se DERIVA del estado (histórico):
     *      "featured" : badge creator_tier = 'featured'
     *      "verified" : isCreator() (rol + approved)
     *      "basic"    : tiene al menos una obra publicada
     *      "none"     : ni siquiera ha publicado
     */
    public function getCreatorLevelAttribute(): string
    {
        // Override explícito del admin (atributo crudo para no recursar el accessor).
        $explicit = $this->attributes['creator_level'] ?? null;
        if ($explicit && $explicit !== 'none') {
            return $explicit;
        }

        if ($this->creator_tier === 'featured') return 'featured';
        if ($this->isCreator()) return 'verified';
        if ($this->booksAsCreator()->where('is_published', true)->exists()) return 'basic';
        return 'none';
    }

    // -------------------- Follow requests (perfiles privados) --------------------

    /** Solicitudes de seguimiento que ME han enviado. */
    public function incomingFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'target_id');
    }

    /** Solicitudes que YO he enviado. */
    public function outgoingFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'requester_id');
    }

    /**
     * Verifica si ESTE usuario tiene una solicitud pendiente
     * para seguir a $target.
     */
    public function hasPendingRequestTo(User|int $target): bool
    {
        $targetId = $target instanceof User ? $target->id : $target;
        return $this->outgoingFollowRequests()
            ->where('target_id', $targetId)
            ->where('status', FollowRequest::STATUS_PENDING)
            ->exists();
    }

    // -------------------- Relaciones sociales --------------------

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** Libros que este user (cuando es creador aprobado) ha publicado. */
    public function booksAsCreator(): HasMany
    {
        return $this->hasMany(Book::class, 'creator_id');
    }

    /** Notificaciones del centro de notificaciones (eventos rápidos). */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** Mensajes recibidos en la bandeja (centro de comunicaciones). */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Widgets de perfil de creador (anuncios, tips, eventos, etc.) */
    public function creatorWidgets(): HasMany
    {
        return $this->hasMany(CreatorWidget::class);
    }

    public function favoriteBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'favorites')->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function anthologies(): HasMany
    {
        return $this->hasMany(Anthology::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** Estante de lectura (libros con estado want_to_read | reading | read). */
    public function shelf(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_user')
            ->withPivot(['status', 'started_at', 'finished_at'])
            ->withTimestamps();
    }

    /** Libros que está leyendo ahora. */
    public function currentlyReading(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_user')
            ->withPivot(['status', 'started_at', 'finished_at'])
            ->withTimestamps()
            ->wherePivot('status', 'reading')
            ->orderByPivot('started_at', 'desc');
    }

    /** Libros terminados. */
    public function readBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_user')
            ->withPivot(['status', 'started_at', 'finished_at'])
            ->withTimestamps()
            ->wherePivot('status', 'read')
            ->orderByPivot('finished_at', 'desc');
    }

    public function hasFavorited(Book|int $book): bool
    {
        $id = $book instanceof Book ? $book->id : $book;
        return $this->favorites()->where('book_id', $id)->exists();
    }

    // -------------------- Grafo de seguidores --------------------

    /**
     * Usuarios a los que ESTE usuario sigue.
     * follows.follower_id = yo  →  following_id = ellos
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'follower_id',
            'following_id'
        )->withTimestamps();
    }

    /**
     * Usuarios que siguen a ESTE usuario.
     * follows.following_id = yo  →  follower_id = ellos
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'following_id',
            'follower_id'
        )->withTimestamps();
    }

    public function isFollowing(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;
        return $this->following()->where('users.id', $id)->exists();
    }

    // -------------------- Username auto-generation --------------------

    /**
     * Genera un username único a partir del email o nombre.
     * Útil para signups OAuth donde el provider no devuelve handle.
     */
    public static function generateUniqueUsername(string $base): string
    {
        // Saneamos: lowercase, solo a-z0-9_, trimmed
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $base));
        if (mb_strlen($base) < 3) {
            $base = 'user' . substr(uniqid(), -6);
        }
        if (mb_strlen($base) > 24) {
            $base = mb_substr($base, 0, 24);
        }

        $candidate = $base;
        $suffix = 0;
        while (static::where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = mb_substr($base, 0, 24) . $suffix;
        }
        return $candidate;
    }
}
