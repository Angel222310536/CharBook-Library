<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de importación de un libro (EPUB/DOCX/TXT) hacia capítulos reales.
 * Sistema aislado del flujo de archivos adjuntos existente.
 */
class ChapterImport extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_PREVIEW_READY = 'preview_ready';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'book_id', 'user_id', 'status',
        'source_name', 'source_ext', 'source_path', 'source_size',
        'detected', 'parsed_path', 'options',
        'total', 'progress', 'created_count', 'warning_count', 'error_count', 'word_count',
        'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'detected' => 'array',
        'options' => 'array',
        'source_size' => 'integer',
        'total' => 'integer',
        'progress' => 'integer',
        'created_count' => 'integer',
        'warning_count' => 'integer',
        'error_count' => 'integer',
        'word_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
