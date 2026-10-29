<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Services\VapidKeyStore;

/**
 * Paire de clés VAPID de l'instance (web push natif). Si openssl ne sait pas la créer, le seeder réussit quand même :
 * le web push est alors indiqué indisponible, rien d'autre ne dépend de ces clés. Une paire existante est conservée.
 */
final class WebPushKeySeeder extends Seeder
{
    public function run(): void
    {
        try {
            (new VapidKeyStore($this->container->make(AppSettingsRepositoryInterface::class)))->ensure();
        } catch (\Throwable) {
            // openssl indisponible : le web push reste désactivé.
        }
    }
}
