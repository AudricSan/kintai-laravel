<?php

namespace App\Navigation;

use App\Models\User;
use InvalidArgumentException;

/**
 * Point d'extension : le Core et les bundles y déclarent les entrées du menu.
 *
 * Un bundle s'enregistre dans le `boot()` de son ServiceProvider :
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
class NavigationRegistry
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

    /** @var array<string, NavigationItem> */
    private array $items = [];

    public function register(NavigationItem $item): void
    {
        if (isset($this->items[$item->key])) {
            throw new InvalidArgumentException("Navigation item [{$item->key}] is already registered.");
        }

        if (! array_key_exists($item->section, self::SECTIONS)) {
            throw new InvalidArgumentException("Navigation section [{$item->section}] is unknown.");
        }

        $this->items[$item->key] = $item;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }

    /**
     * Entrées visibles pour l'utilisateur (droits et conditions), triées par section, ordre, puis enregistrement.
     *
     * @return list<NavigationItem>
     */
    public function visibleTo(User $user): array
    {
        $items = array_values(array_filter(
            $this->items,
            fn (NavigationItem $item): bool => $item->isVisibleTo($user),
        ));

        $sections = array_flip(array_keys(self::SECTIONS));

        // usort est stable depuis PHP 8 : à égalité, l'ordre d'enregistrement est conservé.
        usort($items, fn (NavigationItem $a, NavigationItem $b): int => [$sections[$a->section], $a->order] <=> [$sections[$b->section], $b->order]);

        return $items;
    }
}
