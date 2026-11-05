<?php

namespace App\Navigation;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;

/**
 * Registre en mémoire des entrées du menu (voir l'interface pour le mode d'emploi).
 */
class DefaultNavigationRegistry implements NavigationRegistry
{
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
    public function visibleTo(Authenticatable $user): array
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
