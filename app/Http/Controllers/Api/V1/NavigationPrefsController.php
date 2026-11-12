<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Navigation\SaveNavigationPreferences;
use App\Models\User;
use App\Navigation\NavigationMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kintai\Contracts\Navigation\NavigationItem;

/**
 * Préférences de navigation : entrées masquées et entrées de la barre mobile du bas.
 */
class NavigationPrefsController extends ApiController
{
    public function show(Request $request, int $user, NavigationMenu $menu): JsonResponse
    {
        return $this->payload($menu, $this->targetUser($request, $user, 'employees.view'));
    }

    /**
     * `hidden` : clés à masquer ; `bottom` : 2 à 4 clés de la barre mobile, ou [] / null pour la barre par défaut.
     * Une clé absente de la requête garde sa valeur actuelle.
     */
    public function update(Request $request, int $user, NavigationMenu $menu, SaveNavigationPreferences $save): JsonResponse
    {
        $target = $this->targetUser($request, $user, 'employees.update');

        $data = $request->validate([
            'hidden' => ['nullable', 'array'],
            'hidden.*' => ['string', 'max:100'],
            'bottom' => ['nullable', 'array'],
            'bottom.*' => ['string', 'max:100'],
        ]);

        $save->handle(
            $target,
            array_key_exists('hidden', $data) ? ($data['hidden'] ?? []) : $menu->hiddenKeys($target),
            array_key_exists('bottom', $data) ? ($data['bottom'] ?? []) : ($target->navPref()->first()->bottom_nav_items ?? []),
        );

        return $this->payload($menu, $target);
    }

    private function payload(NavigationMenu $menu, User $user): JsonResponse
    {
        return response()->json(['data' => [
            'user_id' => $user->id,
            'hidden' => $menu->hiddenKeys($user),
            'bottom' => $user->navPref()->first()?->bottom_nav_items,
            'effective_bottom' => array_map(fn (NavigationItem $item): string => $item->key, $menu->bottomBar($user)),
            'items' => array_map(fn (NavigationItem $item): array => [
                'key' => $item->key,
                'label' => __($item->label),
                'hideable' => $item->hideable,
            ], $menu->available($user)),
        ]]);
    }
}
