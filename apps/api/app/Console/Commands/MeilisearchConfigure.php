<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Meilisearch\Client;

class MeilisearchConfigure extends Command
{
    protected $signature = 'meilisearch:configure';
    protected $description = 'Configura los settings del índice books (searchable, filterable, sortable, ranking).';

    public function handle(): int
    {
        $this->info('🔧 Configurando índice Meilisearch "books"...');

        $client = new Client(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key')
        );

        try {
            $index = $client->index('books');

            // ===== Atributos buscables (orden = peso) =====
            $index->updateSearchableAttributes([
                'title',
                'subtitle',
                'author_name',
                'category_names',
                'description',
                'isbn_10',
                'isbn_13',
            ]);
            $this->line('  ✅ searchableAttributes');

            // ===== Atributos para filtrar =====
            $index->updateFilterableAttributes([
                'access_type',
                'language',
                'category_slugs',
                'author_slug',
                'is_featured',
                'publication_year',
            ]);
            $this->line('  ✅ filterableAttributes');

            // ===== Atributos para sort =====
            $index->updateSortableAttributes([
                'views_count',
                'rating_average',
                'created_at_ts',
                'title',
            ]);
            $this->line('  ✅ sortableAttributes');

            // ===== Ranking rules (orden de prioridad) =====
            $index->updateRankingRules([
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
                'rating_average:desc',
                'views_count:desc',
            ]);
            $this->line('  ✅ rankingRules');

            // ===== Sinónimos comunes ES/EN =====
            $index->updateSynonyms([
                'sherlock' => ['sherlock holmes', 'detective'],
                'dracula' => ['drácula', 'vampire', 'vampiro'],
                'odisea' => ['odyssey', 'homero'],
                'crimen' => ['crime', 'castigo', 'dostoyevski'],
            ]);
            $this->line('  ✅ synonyms');

            $this->newLine();
            $this->info('🎉 Configuración aplicada. Recuerda re-indexar con: php artisan scout:import "App\\Models\\Book"');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
