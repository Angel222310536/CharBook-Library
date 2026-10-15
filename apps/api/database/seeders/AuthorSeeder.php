<?php

namespace Database\Seeders;

use App\Models\Author;
use Illuminate\Database\Seeder;

class AuthorSeeder extends Seeder
{
    public function run(): void
    {
        $authors = [
            ['name' => 'Miguel de Cervantes',     'birth_year' => 1547, 'death_year' => 1616, 'nationality' => 'Española',  'biography' => 'Novelista, poeta, dramaturgo y soldado español. Máxima figura de la literatura española.'],
            ['name' => 'William Shakespeare',     'birth_year' => 1564, 'death_year' => 1616, 'nationality' => 'Inglesa',   'biography' => 'Dramaturgo y poeta inglés, considerado el escritor más influyente en la lengua inglesa.'],
            ['name' => 'Jane Austen',             'birth_year' => 1775, 'death_year' => 1817, 'nationality' => 'Inglesa',   'biography' => 'Novelista británica conocida por sus retratos irónicos de la aristocracia inglesa.'],
            ['name' => 'Charles Dickens',         'birth_year' => 1812, 'death_year' => 1870, 'nationality' => 'Inglesa',   'biography' => 'Novelista inglés del periodo victoriano, célebre por sus retratos sociales.'],
            ['name' => 'Fiódor Dostoyevski',      'birth_year' => 1821, 'death_year' => 1881, 'nationality' => 'Rusa',      'biography' => 'Novelista, ensayista y filósofo ruso, uno de los principales escritores de la literatura rusa.'],
            ['name' => 'León Tolstói',            'birth_year' => 1828, 'death_year' => 1910, 'nationality' => 'Rusa',      'biography' => 'Novelista ruso, considerado uno de los más grandes escritores de la literatura universal.'],
            ['name' => 'Mary Shelley',            'birth_year' => 1797, 'death_year' => 1851, 'nationality' => 'Inglesa',   'biography' => 'Novelista, dramaturga y ensayista británica, creadora de Frankenstein.'],
            ['name' => 'Arthur Conan Doyle',      'birth_year' => 1859, 'death_year' => 1930, 'nationality' => 'Escocesa',  'biography' => 'Escritor escocés creador del célebre detective Sherlock Holmes.'],
            ['name' => 'Bram Stoker',             'birth_year' => 1847, 'death_year' => 1912, 'nationality' => 'Irlandesa', 'biography' => 'Escritor irlandés famoso por su novela Drácula.'],
            ['name' => 'Lewis Carroll',           'birth_year' => 1832, 'death_year' => 1898, 'nationality' => 'Inglesa',   'biography' => 'Matemático y escritor inglés, autor de Alicia en el País de las Maravillas.'],
            ['name' => 'Oscar Wilde',             'birth_year' => 1854, 'death_year' => 1900, 'nationality' => 'Irlandesa', 'biography' => 'Escritor, poeta y dramaturgo irlandés, exponente del esteticismo.'],
            ['name' => 'Edgar Allan Poe',         'birth_year' => 1809, 'death_year' => 1849, 'nationality' => 'Estadounidense', 'biography' => 'Escritor, poeta y crítico literario, pionero del relato corto y del cuento de terror.'],
            ['name' => 'H.G. Wells',              'birth_year' => 1866, 'death_year' => 1946, 'nationality' => 'Inglesa',   'biography' => 'Escritor inglés, padre de la ciencia ficción moderna junto a Verne.'],
            ['name' => 'Julio Verne',             'birth_year' => 1828, 'death_year' => 1905, 'nationality' => 'Francesa',  'biography' => 'Novelista francés pionero del género de ciencia ficción y aventuras.'],
            ['name' => 'Franz Kafka',             'birth_year' => 1883, 'death_year' => 1924, 'nationality' => 'Austrohúngara', 'biography' => 'Escritor en lengua alemana, una de las figuras más influyentes de la literatura del siglo XX.'],
            ['name' => 'Mark Twain',              'birth_year' => 1835, 'death_year' => 1910, 'nationality' => 'Estadounidense', 'biography' => 'Escritor, periodista y humorista estadounidense, autor de Tom Sawyer y Huckleberry Finn.'],
            ['name' => 'Homero',                  'birth_year' => -800, 'death_year' => -750, 'nationality' => 'Griega',    'biography' => 'Poeta de la Antigua Grecia, tradicionalmente atribuido como autor de la Ilíada y la Odisea.'],
            ['name' => 'Friedrich Nietzsche',     'birth_year' => 1844, 'death_year' => 1900, 'nationality' => 'Alemana',   'biography' => 'Filósofo, poeta y filólogo alemán, una de las figuras más influyentes del pensamiento moderno.'],
            ['name' => 'Platón',                  'birth_year' => -427, 'death_year' => -347, 'nationality' => 'Griega',    'biography' => 'Filósofo griego, discípulo de Sócrates y maestro de Aristóteles, fundador de la Academia.'],
            ['name' => 'Alejandro Dumas',         'birth_year' => 1802, 'death_year' => 1870, 'nationality' => 'Francesa',  'biography' => 'Escritor francés célebre por El Conde de Montecristo y Los Tres Mosqueteros.'],
        ];

        foreach ($authors as $author) {
            Author::firstOrCreate(['name' => $author['name']], $author);
        }

        $this->command->info(count($authors) . ' autores creados.');
    }
}
