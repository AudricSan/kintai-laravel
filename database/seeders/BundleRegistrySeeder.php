<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Registre officiel de bundles (config/official-bundle-registry.php), pour que /admin/bundles/registries ne parte
 * jamais vide.
 */
final class BundleRegistrySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('bundle_registries')->where('is_official', true)->exists()) {
            return;
        }

        $official = require base_path('config/official-bundle-registry.php');

        DB::table('bundle_registries')->insert([
            'name'        => $official['name'],
            'url'         => $official['url'],
            'is_official' => true,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
