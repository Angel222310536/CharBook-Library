<?php

namespace Database\Seeders;

use App\Models\CoinPackage;
use App\Services\PlatformSettings;
use Illuminate\Database\Seeder;

/**
 * Paquetes de monedas iniciales + persistencia de los settings por defecto
 * (quedan visibles/editables en la tabla platform_settings).
 */
class MonetizationSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['name' => 'Puñado', 'coins' => 100, 'bonus_coins' => 0, 'price_usd' => 0.99, 'sort' => 1],
            ['name' => 'Bolsa', 'coins' => 500, 'bonus_coins' => 25, 'price_usd' => 4.99, 'sort' => 2],
            ['name' => 'Cofre', 'coins' => 1000, 'bonus_coins' => 80, 'price_usd' => 9.99, 'sort' => 3],
            ['name' => 'Tesoro', 'coins' => 2500, 'bonus_coins' => 300, 'price_usd' => 24.99, 'sort' => 4],
        ];

        foreach ($packages as $p) {
            CoinPackage::updateOrCreate(['name' => $p['name']], $p + ['is_active' => true]);
        }

        // Materializa los defaults en BD para que el admin los vea/edite
        foreach (PlatformSettings::DEFAULTS as $key => $value) {
            if (! \DB::table('platform_settings')->where('key', $key)->exists()) {
                PlatformSettings::set($key, $value);
            }
        }
    }
}
