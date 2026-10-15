<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Crea (o actualiza) la jerarquía de categorías de CharBook.
 *
 * Re-ejecutable y defensivo:
 *  - Los padres se buscan por slug del nombre top-level (ej. "literatura-y-ficcion").
 *    Si ya existe, se reusa.
 *  - Los hijos se buscan por slug compuesto "padre-hijo" (ej. "literatura-y-ficcion-romance").
 *    Si ya existe una categoría top-level con el mismo nombre del hijo, se ADOPTA
 *    como hija del nuevo padre en lugar de duplicarse.
 */
class HierarchicalCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::HIERARCHY as $parentName => $children) {
            $parentSlug = Str::slug($parentName);

            // 1) Padre top-level
            $parent = Category::firstOrCreate(
                ['slug' => $parentSlug],
                ['name' => $parentName, 'parent_id' => null]
            );

            foreach ($children as $childName) {
                $childSlug = Str::slug($parentName . '-' . $childName);

                // 2a) ¿ya existe el hijo bajo ese slug compuesto? → solo asegurar parent_id
                $existing = Category::where('slug', $childSlug)->first();
                if ($existing) {
                    if ($existing->parent_id !== $parent->id) {
                        $existing->update(['parent_id' => $parent->id]);
                    }
                    continue;
                }

                // 2b) ¿hay una categoría top-level con el MISMO nombre (sin parent)?
                //     → adoptarla como hija del nuevo padre y renombrar su slug.
                $candidate = Category::where('name', $childName)
                    ->whereNull('parent_id')
                    ->first();
                if ($candidate) {
                    $candidate->update([
                        'parent_id' => $parent->id,
                        'slug' => $childSlug,
                    ]);
                    continue;
                }

                // 2c) Crear hija nueva
                Category::create([
                    'slug' => $childSlug,
                    'name' => $childName,
                    'parent_id' => $parent->id,
                ]);
            }
        }
    }

    private const HIERARCHY = [
        'Literatura y ficción' => [
            'Fantasía', 'Romance', 'Terror', 'Ciencia ficción', 'Distopía',
            'Misterio', 'Thriller', 'Drama', 'Aventura', 'Acción',
            'Sobrenatural', 'Juvenil', 'Histórico',
        ],
        'Educación' => [
            'Matemáticas', 'Física', 'Química', 'Biología', 'Idiomas',
            'Historia', 'Geografía', 'Literatura', 'Ciencias Sociales',
        ],
        'Tecnología' => [
            'Programación', 'Inteligencia Artificial', 'Desarrollo Web',
            'Ciberseguridad', 'Bases de Datos', 'Cloud Computing',
            'DevOps', 'Diseño UX/UI', 'Desarrollo Mobile', 'Videojuegos',
        ],
        'Negocios y emprendimiento' => [
            'Marketing', 'Ventas', 'Finanzas personales', 'Inversiones',
            'Liderazgo', 'Productividad', 'Startups',
        ],
        'Historia' => [
            'Historia universal', 'Historia antigua', 'Historia moderna',
            'Historia regional', 'Biografías históricas',
        ],
        'Ciencias' => [
            'Astronomía', 'Medicina', 'Neurociencia', 'Ecología',
            'Genética', 'Divulgación científica',
        ],
        'Arte y diseño' => [
            'Pintura', 'Fotografía', 'Diseño gráfico', 'Arquitectura',
            'Cine', 'Música',
        ],
        'Desarrollo personal' => [
            'Autoayuda', 'Hábitos', 'Mindfulness', 'Psicología práctica',
            'Espiritualidad', 'Relaciones',
        ],
        'Infantil y juvenil' => [
            'Cuentos cortos', 'Lecturas infantiles', 'Aventuras juveniles',
            'Educativo infantil', 'Fábulas',
        ],
        'Entretenimiento' => [
            'Humor', 'Trivia', 'Cultura pop', 'Pasatiempos',
        ],
        'Poesía' => [
            'Poesía contemporánea', 'Poesía clásica', 'Micropoesía',
            'Verso libre', 'Soneto',
        ],
        'Cómics y novelas gráficas' => [
            'Superhéroes', 'Manga', 'Manhwa', 'Webcomic',
            'Cómic europeo', 'Novela gráfica',
        ],
        'Investigación' => [
            'Artículos académicos', 'Tesis', 'Estudios de caso',
            'Reseñas críticas',
        ],
        'Religión y filosofía' => [
            'Filosofía clásica', 'Ética', 'Religiones comparadas',
            'Espiritualidad', 'Teología',
        ],
    ];
}
