<?php

namespace App\Bundles;

use App\Models\Role;
use App\Settings\AppSettings;
use Kintai\Contracts\Bundle\BundleServiceProvider;

/**
 * Droits par défaut d'un bundle (`BundleServiceProvider::permissions()`, paramètre `$defaults`). Appliqués une seule fois,
 * à la première activation du bundle : les slugs déjà « initialisés » sont mémorisés (réglage `bundles.initialized`),
 * donc une réactivation ne remet pas des droits que l'Owner a retirés depuis. Seuls les rôles `manager` et `employee`
 * du Core sont concernés : jamais l'Owner, jamais un rôle personnalisé. Une permission déjà présente n'est pas modifiée.
 */
class DefaultPermissionGrant
{
    public const SETTING = 'bundles.initialized';

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return array<string, list<string>>|null Permissions ajoutées par slug de rôle ; null si le bundle était déjà
     *                                          initialisé (rien n'est fait).
     */
    public function grantOnFirstActivation(BundleServiceProvider $bundle): ?array
    {
        $initialized = $this->initialized();

        if (in_array($bundle->slug(), $initialized, true)) {
            return null;
        }

        $this->settings->set(self::SETTING, (string) json_encode([...$initialized, $bundle->slug()]));

        $granted = [];

        foreach ($bundle->defaultPermissions() as $slug => $permissions) {
            if (! in_array($slug, BundleServiceProvider::DEFAULT_ROLES, true)) {
                continue;
            }

            $role = Role::query()->where('slug', $slug)->where('is_system', false)->first();

            if ($role === null) {
                continue;
            }

            foreach ($permissions as $permission) {
                $row = $role->permissions()->firstOrCreate(['permission' => $permission], ['all_stores' => false]);

                if ($row->wasRecentlyCreated) {
                    $granted[$slug][] = $permission;
                }
            }
        }

        return $granted;
    }

    /**
     * @return list<string>
     */
    private function initialized(): array
    {
        $decoded = json_decode($this->settings->string(self::SETTING), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
