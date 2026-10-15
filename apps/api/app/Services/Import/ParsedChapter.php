<?php

namespace App\Services\Import;

/**
 * Un capítulo detectado por un parser, listo para convertirse en `Chapter`.
 * Solo texto plano (Fase A): compatible con el lector, TTS y monetización
 * actuales sin tocar nada.
 */
class ParsedChapter
{
    public function __construct(
        public string $title,
        public string $body,
        public int $wordCount,
        /** Aviso no fatal (p. ej. "capítulo vacío", "recortado"). */
        public ?string $warning = null,
    ) {}

    public function toPreview(int $order): array
    {
        return [
            'order' => $order,
            'title' => $this->title,
            'word_count' => $this->wordCount,
            'char_count' => mb_strlen($this->body),
            'warning' => $this->warning,
        ];
    }
}
