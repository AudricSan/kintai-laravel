<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\BundleDiscoveryService;
use kintai\Core\Database\Migration;

/**
 * Le bundle « feedback » n'existe plus : on retire ses traces en base.
 *
 *  - la table employee_feedbacks (les retours déjà enregistrés sont perdus, décision du propriétaire du projet) ;
 *  - les permissions feedbacks.* attribuées aux rôles ;
 *  - le slug dans le réglage Owner « enabled_bundles » et dans la liste des bundles installés.
 *
 * Le dossier storage/bundles/feedback d'une instance qui l'avait installé n'est plus chargé :
 * BundleDiscoveryService ignore les slugs retirés (RETIRED_SLUGS).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->dropIfExists('employee_feedbacks');

        $db     = $this->capsule->getConnection();
        $schema = $this->schema();

        if ($schema->hasTable('role_permissions')) {
            $db->table('role_permissions')->where('permission_key', 'like', 'feedbacks.%')->delete();
        }

        if ($schema->hasTable('installed_bundles')) {
            $db->table('installed_bundles')->whereIn('slug', BundleDiscoveryService::RETIRED_SLUGS)->delete();
        }

        if ($schema->hasTable('app_settings')) {
            $raw = $db->table('app_settings')->where('key', 'enabled_bundles')->value('value');
            $list = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($list)) {
                $kept = array_values(array_diff($list, BundleDiscoveryService::RETIRED_SLUGS));
                if ($kept !== $list) {
                    $db->table('app_settings')->where('key', 'enabled_bundles')->update(['value' => json_encode($kept)]);
                }
            }
        }
    }

    public function down(): void
    {
        // Irréversible : le bundle et ses données n'existent plus.
    }
};
