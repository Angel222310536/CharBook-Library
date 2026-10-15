<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class FileUploadAudit extends Model
{
    public const UPDATED_AT = null; // solo created_at

    protected $guarded = [];

    /**
     * Registra un intento de subida (aceptado o rechazado). Defensivo: nunca
     * debe romper el flujo de subida si el log falla.
     */
    public static function record(
        Request $request,
        string $kind,
        ?UploadedFile $file,
        string $result,
        ?string $reason = null,
    ): void {
        try {
            self::create([
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'kind' => $kind,
                'original_name' => $file ? mb_substr((string) $file->getClientOriginalName(), 0, 255) : null,
                'declared_ext' => $file ? mb_substr(strtolower((string) $file->getClientOriginalExtension()), 0, 16) : null,
                'detected_mime' => $file ? mb_substr((string) ($file->getMimeType() ?? ''), 0, 128) : null,
                'size_bytes' => $file?->getSize(),
                'result' => $result,
                'reason' => $reason ? mb_substr($reason, 0, 1000) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
