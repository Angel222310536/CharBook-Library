<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'es', 'name_native' => 'Español',    'name_en' => 'Spanish',    'flag_emoji' => '🇪🇸', 'is_ui_supported' => true,  'sort_order' => 1],
            ['code' => 'en', 'name_native' => 'English',    'name_en' => 'English',    'flag_emoji' => '🇺🇸', 'is_ui_supported' => true,  'sort_order' => 2],
            ['code' => 'pt', 'name_native' => 'Português',  'name_en' => 'Portuguese', 'flag_emoji' => '🇧🇷', 'is_ui_supported' => false, 'sort_order' => 3],
            ['code' => 'fr', 'name_native' => 'Français',   'name_en' => 'French',     'flag_emoji' => '🇫🇷', 'is_ui_supported' => false, 'sort_order' => 4],
            ['code' => 'de', 'name_native' => 'Deutsch',    'name_en' => 'German',     'flag_emoji' => '🇩🇪', 'is_ui_supported' => false, 'sort_order' => 5],
            ['code' => 'it', 'name_native' => 'Italiano',   'name_en' => 'Italian',    'flag_emoji' => '🇮🇹', 'is_ui_supported' => false, 'sort_order' => 6],
            ['code' => 'ja', 'name_native' => '日本語',     'name_en' => 'Japanese',   'flag_emoji' => '🇯🇵', 'is_ui_supported' => false, 'sort_order' => 7],
        ];

        foreach ($languages as $lang) {
            Language::updateOrCreate(['code' => $lang['code']], $lang + ['is_active' => true]);
        }

        $this->command->info(count($languages) . ' idiomas seedeados (2 con UI soportada).');
    }
}
