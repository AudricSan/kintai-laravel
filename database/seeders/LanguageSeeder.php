<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

/** Langues livrées avec l'application ; une langue ajoutée ou désactivée par l'Owner n'est pas modifiée. */
final class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        foreach (Language::BUILT_IN as $code => $name) {
            Language::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true, 'sort_order' => $order]);
            $order++;
        }
    }
}
