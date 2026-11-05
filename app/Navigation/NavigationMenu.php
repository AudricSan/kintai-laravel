<?php

namespace App\Navigation;

use App\Models\User;
use App\Models\UserNavPref;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;

/**
 * Menu d'un utilisateur : entrées du registre visibles pour lui, moins celles qu'il a masquées, et barre mobile du
 * bas. Les préférences sont lues une fois par utilisateur et par requête.
 */
class NavigationMenu
{
    public const BOTTOM_MIN = 2;

    public const BOTTOM_MAX = 4;

    /** Barre mobile sans préférence : les premières de ces entrées visibles. */
    public const DEFAULT_BOTTOM = ['dashboard', 'my-shifts', 'shifts', 'requests'];

    /** @var array<int, UserNavPref|null> */
    private array $prefs = [];

    public function __construct(private readonly NavigationRegistry $registry) {}

    public function forget(User $user): void
    {
        unset($this->prefs[$user->id]);
    }

    /**
     * Entrées accessibles à l'utilisateur (droits et conditions), masquées ou non.
     *
     * @return list<NavigationItem>
     */
    public function available(User $user): array
    {
        return $this->registry->visibleTo($user);
    }

    /**
     * Entrées que l'utilisateur peut masquer.
     *
     * @return list<NavigationItem>
     */
    public function hideable(User $user): array
    {
        return array_values(array_filter($this->available($user), fn (NavigationItem $item): bool => $item->hideable));
    }

    /**
     * @return list<string>
     */
    public function hiddenKeys(User $user): array
    {
        $hidden = $this->prefs($user)->hidden_items ?? [];

        return array_values(array_filter($hidden, 'is_string'));
    }

    /**
     * Sections à afficher, dans l'ordre ; une section sans entrée n'est pas listée.
     *
     * @return list<array{key: string, heading: string|null, items: list<NavigationItem>}>
     */
    public function sections(User $user): array
    {
        $grouped = [];
        foreach ($this->visibleItems($user) as $item) {
            $grouped[$item->section][] = $item;
        }

        $sections = [];
        foreach (NavigationRegistry::SECTIONS as $key => $heading) {
            if (isset($grouped[$key])) {
                $sections[] = ['key' => $key, 'heading' => $heading, 'items' => $grouped[$key]];
            }
        }

        return $sections;
    }

    /**
     * Barre mobile du bas : les entrées choisies (parmi les visibles), sinon les entrées par défaut.
     *
     * @return list<NavigationItem>
     */
    public function bottomBar(User $user): array
    {
        $visible = [];
        foreach ($this->visibleItems($user) as $item) {
            $visible[$item->key] = $item;
        }

        $saved = array_values(array_filter($this->prefs($user)->bottom_nav_items ?? [], 'is_string'));
        $chosen = array_values(array_filter(array_unique($saved), fn (string $key): bool => isset($visible[$key])));

        if (count($chosen) < self::BOTTOM_MIN || count($chosen) > self::BOTTOM_MAX) {
            $chosen = array_slice(array_values(array_filter(self::DEFAULT_BOTTOM, fn (string $key): bool => isset($visible[$key]))), 0, self::BOTTOM_MAX);

            // Complète avec les premières entrées visibles si les entrées par défaut sont trop peu nombreuses.
            foreach (array_keys($visible) as $key) {
                if (count($chosen) >= self::BOTTOM_MIN) {
                    break;
                }
                if (! in_array($key, $chosen, true)) {
                    $chosen[] = $key;
                }
            }
        }

        // Dans l'ordre du menu.
        return array_values(array_filter($visible, fn (NavigationItem $item): bool => in_array($item->key, $chosen, true)));
    }

    /**
     * @return list<NavigationItem>
     */
    private function visibleItems(User $user): array
    {
        $hidden = $this->hiddenKeys($user);

        return array_values(array_filter(
            $this->available($user),
            fn (NavigationItem $item): bool => ! ($item->hideable && in_array($item->key, $hidden, true)),
        ));
    }

    private function prefs(User $user): ?UserNavPref
    {
        if (! array_key_exists($user->id, $this->prefs)) {
            $this->prefs[$user->id] = UserNavPref::query()->where('user_id', $user->id)->first();
        }

        return $this->prefs[$user->id];
    }
}
