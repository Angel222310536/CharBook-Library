<?php

namespace App\Services;

use App\Models\CreatorApplication;
use App\Models\Report;
use App\Models\User;

/**
 * Lógica de aprobación de Creadores.
 *
 * Capa 1: prerrequisitos automáticos (gate).
 * Capa 2: rúbrica manual 4 ejes (mínimo 8/12 para aprobar).
 * Capa 3: post-aprobación (asignar rol, set status, trust_score inicial).
 */
class CreatorApplicationService
{
    public const MIN_SCORE_TO_APPROVE = 8;
    public const COOLDOWN_AFTER_REJECT_DAYS = 30;
    public const INITIAL_TRUST_SCORE = 25;

    // -------------------- CAPA 1: prerrequisitos --------------------

    /**
     * Comprueba si el usuario puede SIQUIERA postularse.
     * Devuelve checklist con qué cumple y qué no. NO bloquea, informa.
     */
    public function checkPrerequisites(User $user): array
    {
        $accountDays = $user->created_at->diffInDays(now());
        $hasEmail = ! empty($user->email);
        $hasUsername = ! empty($user->username);
        $hasBio = ! empty($user->bio);
        $hasAvatar = ! empty($user->avatar);
        $reviewsCount = $user->reviews()->count();
        $publicAnthologiesWithBooks = $user->anthologies()
            ->where('is_public', true)
            ->withCount('books')
            ->get()
            ->filter(fn($a) => $a->books_count >= 3)
            ->count();
        $activeReports = Report::for('user', $user->id)
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_IN_REVIEW])
            ->count();

        $checks = [
            'account_age' => [
                'label' => 'Cuenta con al menos 14 días',
                'met' => $accountDays >= 14,
                'progress' => "{$accountDays}/14 días",
            ],
            'email_verified' => [
                'label' => 'Email registrado',
                'met' => $hasEmail,
                'progress' => $hasEmail ? 'OK' : 'Falta',
            ],
            'profile_complete' => [
                'label' => 'Perfil con username, bio y avatar',
                'met' => $hasUsername && $hasBio && $hasAvatar,
                'progress' => implode(' · ', array_filter([
                    $hasUsername ? null : 'falta username',
                    $hasBio ? null : 'falta bio',
                    $hasAvatar ? null : 'falta avatar',
                ])) ?: 'completo',
            ],
            'reviews' => [
                'label' => 'Al menos 5 reseñas escritas',
                'met' => $reviewsCount >= 5,
                'progress' => "{$reviewsCount}/5",
            ],
            'anthology' => [
                'label' => 'Al menos 1 antología pública con 3+ libros',
                'met' => $publicAnthologiesWithBooks >= 1,
                'progress' => $publicAnthologiesWithBooks >= 1 ? 'OK' : 'Crea una antología pública con 3 libros',
            ],
            'no_active_reports' => [
                'label' => 'Sin reportes activos en tu contra',
                'met' => $activeReports === 0,
                'progress' => $activeReports === 0 ? 'OK' : "{$activeReports} reporte(s) activo(s)",
            ],
        ];

        $allMet = collect($checks)->every(fn($c) => $c['met']);

        return [
            'can_apply' => $allMet,
            'checks' => $checks,
        ];
    }

    /**
     * Adicional: verifica si el usuario está en cooldown post-rechazo.
     * Devuelve días restantes o null si puede aplicar ya.
     */
    public function getCooldownRemainingDays(User $user): ?int
    {
        $lastRejected = $user->creatorApplications()
            ->where('status', CreatorApplication::STATUS_REJECTED)
            ->latest('reviewed_at')
            ->first();

        if (! $lastRejected) return null;

        $unlockAt = $lastRejected->reviewed_at?->addDays(self::COOLDOWN_AFTER_REJECT_DAYS);
        if (! $unlockAt || $unlockAt->isPast()) return null;

        return (int) ceil($unlockAt->diffInDays(now()));
    }

    /**
     * Si tiene una solicitud activa, no puede crear otra.
     */
    public function hasActiveApplication(User $user): bool
    {
        return $user->creatorApplications()
            ->whereIn('status', CreatorApplication::ACTIVE_STATUSES)
            ->exists();
    }

    // -------------------- CAPA 2: revisión con rúbrica --------------------

    /**
     * Aplica scoring + decide. Solo guarda; el caller decide el siguiente status.
     */
    public function scoreApplication(
        CreatorApplication $app,
        User $reviewer,
        int $quality,
        int $commitment,
        int $professionalism,
        int $fit,
    ): int {
        $app->reviewer_id = $reviewer->id;
        $app->score_quality = max(0, min(3, $quality));
        $app->score_commitment = max(0, min(3, $commitment));
        $app->score_professionalism = max(0, min(3, $professionalism));
        $app->score_fit = max(0, min(3, $fit));
        return $app->calculateScore();
    }

    // -------------------- CAPA 3: efectos de decisión --------------------

    /**
     * Aprueba la solicitud: asigna rol, status, trust_score inicial.
     */
    public function approve(CreatorApplication $app, User $reviewer, ?string $notes = null): void
    {
        // Score mínimo enforcement defensivo
        if (($app->score_total ?? 0) < self::MIN_SCORE_TO_APPROVE) {
            throw new \DomainException(
                'Score insuficiente para aprobar. Mínimo ' . self::MIN_SCORE_TO_APPROVE . '/12.'
            );
        }

        $app->update([
            'status' => CreatorApplication::STATUS_APPROVED,
            'reviewer_id' => $reviewer->id,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        $user = $app->user;
        $user->update([
            'creator_status' => 'approved',
            'trust_score' => self::INITIAL_TRUST_SCORE,
        ]);
        // Asignar rol Spatie
        if (! $user->hasRole('creator')) {
            $user->assignRole('creator');
        }
    }

    public function reject(CreatorApplication $app, User $reviewer, string $notes): void
    {
        $app->update([
            'status' => CreatorApplication::STATUS_REJECTED,
            'reviewer_id' => $reviewer->id,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        $app->user->update(['creator_status' => 'rejected']);
    }

    public function requestChanges(CreatorApplication $app, User $reviewer, string $notes): void
    {
        $app->update([
            'status' => CreatorApplication::STATUS_CHANGES_REQUESTED,
            'reviewer_id' => $reviewer->id,
            'reviewer_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        $app->user->update(['creator_status' => 'changes_requested']);
    }
}
