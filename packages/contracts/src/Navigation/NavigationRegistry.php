<?php

namespace Kintai\Contracts\Navigation;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'extension : le Core et les bundles y déclarent les entrées du menu.
 *
 * Un bundle s'enregistre dans le `boot()` de son ServiceProvider (de préférence par l'aide
 * `BundleServiceProvider::navigation()`, qui ne s'applique que si le bundle est activé) :
 *
 *     $this->app->make(NavigationRegistry::class)->register(new NavigationItem(
 *         key: 'timeoff', label: 'Time off', route: 'timeoff.index', icon: 'sun',
 *         section: NavigationRegistry::SECTION_REQUESTS, order: 20, permission: 'timeoff.view',
 *     ));
 *
 * La clé est unique (un doublon est refusé) et sert aux préférences de navigation. Les entrées dont le droit ou la
 * condition échoue n'apparaissent pas ; une section sans entrée visible n'apparaît pas. Le Core fonctionne avec un
 * registre qui ne contient que ses propres entrées.
 */
interface NavigationRegistry
{
    public const SECTION_MAIN = 'main';

    public const SECTION_PLANNING = 'planning';

    public const SECTION_REQUESTS = 'requests';

    public const SECTION_REPORTS = 'reports';

    public const SECTION_HR = 'hr';

    public const SECTION_SYSTEM = 'system';

    public const SECTION_ACCOUNT = 'account';

    /**
     * Sections dans l'ordre d'affichage ; clé => titre (clé de traduction, null = sans titre).
     */
    public const SECTIONS = [
        self::SECTION_MAIN => null,
        self::SECTION_PLANNING => 'Planning',
        self::SECTION_REQUESTS => 'Requests',
        self::SECTION_REPORTS => 'Statistics',
        self::SECTION_HR => 'Human resources',
        self::SECTION_SYSTEM => 'System',
        self::SECTION_ACCOUNT => 'Account',
    ];

    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée ou section inconnue.
     */
    public function register(NavigationItem $item): void;

    public function has(string $key): bool;

    /**
     * Entrées visibles pour l'utilisateur (droits et conditions), triées par section, ordre, puis enregistrement.
     *
     * @return list<NavigationItem>
     */
    public function visibleTo(Authenticatable $user): array;
}
