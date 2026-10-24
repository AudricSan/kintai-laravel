<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Ajoute le registry officiel Kintai (config/official-bundle-registry.php)
 * dès l'installation ou la mise à jour, pour que /admin/bundles/registries
 * ne parte jamais vide. Exécutée comme migration (données structurelles),
 * pas comme seed optionnel — voir 2026_07_14_000003_seed_default_roles.php
 * pour le même choix.
 */
return new class($this->capsule) extends Migration {
    public function isSeed(): bool
    {
        return true;
    }

    public function up(): void
    {
        $conn = $this->capsule->getConnection();
        $registries = $conn->table('bundle_registries');

        if ($registries->where('is_official', true)->exists()) {
            return;
        }

        // Chemin relatif au fichier plutôt que BASE_PATH : cette migration doit
        // pouvoir tourner même dans un contexte de test qui ne définit jamais
        // cette constante globale (voir MigrationRunnerTest/EmployeeFeedbacksSchemaTest).
        $official = require dirname(__DIR__, 3) . '/config/official-bundle-registry.php';

        $registries->insert([
            'name'        => $official['name'],
            'url'         => $official['url'],
            'is_official' => true,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $conn = $this->capsule->getConnection();
        $conn->table('bundle_registries')->where('is_official', true)->delete();
    }
};
