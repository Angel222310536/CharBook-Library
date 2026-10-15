<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Ficción',          'icon' => 'book-open',  'color' => '#c9a961', 'description' => 'Novelas y relatos imaginarios.'],
            ['name' => 'Clásicos',         'icon' => 'feather',    'color' => '#8b6f47', 'description' => 'Obras maestras de la literatura universal.'],
            ['name' => 'Misterio',         'icon' => 'search',     'color' => '#5d4e75', 'description' => 'Suspenso, detectives y enigmas.'],
            ['name' => 'Romance',          'icon' => 'heart',      'color' => '#c1666b', 'description' => 'Historias de amor y pasión.'],
            ['name' => 'Ciencia Ficción',  'icon' => 'rocket',     'color' => '#4a6fa5', 'description' => 'Futuro, ciencia y mundos imaginados.'],
            ['name' => 'Filosofía',        'icon' => 'lightbulb',  'color' => '#7d6608', 'description' => 'Pensamiento, ética y existencia.'],
            ['name' => 'Aventura',         'icon' => 'compass',    'color' => '#2e8b57', 'description' => 'Viajes, exploración y acción.'],
            ['name' => 'Terror',           'icon' => 'ghost',      'color' => '#2c1810', 'description' => 'Miedo, lo sobrenatural y lo oscuro.'],
            ['name' => 'Historia',         'icon' => 'landmark',   'color' => '#6b4423', 'description' => 'Eventos y figuras del pasado.'],
            ['name' => 'Poesía',           'icon' => 'pen-tool',   'color' => '#9b59b6', 'description' => 'Versos, lírica y prosa poética.'],
            ['name' => 'Drama',            'icon' => 'theater',    'color' => '#8b0000', 'description' => 'Obras teatrales y tragedias.'],
            ['name' => 'Infantil',         'icon' => 'smile',      'color' => '#f39c12', 'description' => 'Libros para los más pequeños.'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['name' => $cat['name']], $cat);
        }

        $this->command->info(count($categories) . ' categorías creadas.');
    }
}
