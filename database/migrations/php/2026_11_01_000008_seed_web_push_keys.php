<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Repositories\DatabaseAppSettingsRepository;
use kintai\Core\Services\VapidKeyStore;

/**
 * Paire de clés VAPID de l'instance (web push natif). Si openssl ne sait pas la créer, la migration réussit quand même :
 * le web push est alors indiqué indisponible, rien d'autre ne dépend de ces clés. Une paire existante est conservée.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        try {
            (new VapidKeyStore(new DatabaseAppSettingsRepository()))->ensure();
        } catch (\Throwable) {
            // Table app_settings absente ou openssl indisponible : le web push reste désactivé.
        }
    }

    public function down(): void
    {
    }
};
