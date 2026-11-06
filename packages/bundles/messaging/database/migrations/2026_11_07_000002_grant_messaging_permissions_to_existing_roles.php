<?php

use App\Models\Role;
use App\Settings\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Avant les permissions `messaging.*` (1.1.0), la messagerie était ouverte à tous. Sur une installation où le bundle a déjà
 * été activé (donc « initialisé » : les droits par défaut du Core ne seront plus appliqués), chaque rôle non système,
 * personnalisés compris, reçoit les trois permissions pour que personne ne perde la messagerie ; l'Owner peut ensuite les
 * retirer. Sans effet tant que le bundle n'a jamais été activé (les droits par défaut s'en chargent alors).
 */
return new class extends Migration
{
    private const PERMISSIONS = ['messaging.view', 'messaging.send', 'messaging.create'];

    public function up(): void
    {
        if (! Schema::hasTable('app_settings') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $decoded = json_decode(app(AppSettings::class)->string('bundles.initialized'), true);

        if (! is_array($decoded) || ! in_array('messaging', $decoded, true)) {
            return;
        }

        foreach (Role::query()->where('is_system', false)->get() as $role) {
            foreach (self::PERMISSIONS as $permission) {
                $role->permissions()->firstOrCreate(['permission' => $permission], ['all_stores' => true]);
            }
        }
    }
};
