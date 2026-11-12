<?php

namespace App\Actions\Navigation;

use App\Models\User;
use App\Models\UserNavPref;
use App\Navigation\NavigationMenu;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Navigation\NavigationItem;

class SaveNavigationPreferences
{
    public function __construct(private readonly NavigationMenu $menu) {}

    /**
     * Les clés inconnues, non autorisées pour l'utilisateur ou non masquables sont ignorées. Une barre mobile vide
     * revient à la barre par défaut ; sinon elle doit compter 2 à 4 entrées.
     *
     * @param  array<int, mixed>  $hidden  Entrées à masquer
     * @param  array<int, mixed>  $bottom  Entrées de la barre mobile
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $hidden, array $bottom): UserNavPref
    {
        $available = array_map(fn (NavigationItem $item): string => $item->key, $this->menu->available($user));
        $hideable = array_map(fn (NavigationItem $item): string => $item->key, $this->menu->hideable($user));

        $hidden = array_values(array_unique(array_filter($hidden, fn ($key): bool => is_string($key) && in_array($key, $hideable, true))));
        $bottom = array_values(array_unique(array_filter($bottom, fn ($key): bool => is_string($key) && in_array($key, $available, true))));

        if ($bottom !== [] && (count($bottom) < NavigationMenu::BOTTOM_MIN || count($bottom) > NavigationMenu::BOTTOM_MAX)) {
            throw ValidationException::withMessages([
                'bottom' => __('Choose between :min and :max entries for the mobile bar.', ['min' => NavigationMenu::BOTTOM_MIN, 'max' => NavigationMenu::BOTTOM_MAX]),
            ]);
        }

        $pref = UserNavPref::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['hidden_items' => $hidden, 'bottom_nav_items' => $bottom === [] ? null : $bottom],
        );

        $this->menu->forget($user);

        return $pref;
    }
}
