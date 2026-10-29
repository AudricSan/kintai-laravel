<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données structurelles nécessaires à toute instance (rôles, registre officiel de bundles, clés du web push). Chaque
 * seeder est idempotent : `php artisan db:seed` peut être rejoué sans doublon, et l'est après une remise à zéro.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            BundleRegistrySeeder::class,
            WebPushKeySeeder::class,
        ]);
    }
}
