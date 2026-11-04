<?php

namespace App\Activity;

use InvalidArgumentException;

/**
 * Point d'extension : le Core et les bundles y déclarent le libellé de leurs actions de journal.
 *
 * Un bundle s'y enregistre depuis son fournisseur de services, au démarrage :
 *
 *     app(ActivityActionRegistry::class)->register('timeoff.approved', 'Time off approved', 'Time off');
 *
 * Le libellé et la catégorie sont des clés de traduction (à ajouter dans `lang/*.json`), traduites à l'affichage.
 * Une action absente du registre s'affiche par sa clé brute.
 */
class ActivityActionRegistry
{
    /** @var array<string, array{label: string, category: string}> */
    private array $actions = [];

    public function register(string $key, string $label, string $category): void
    {
        if (isset($this->actions[$key])) {
            throw new InvalidArgumentException("Activity action [{$key}] is already registered.");
        }

        $this->actions[$key] = ['label' => $label, 'category' => $category];
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    /**
     * @return array<string, array{label: string, category: string}>
     */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Libellé traduit, ou la clé brute pour une action inconnue.
     */
    public function label(string $key): string
    {
        return isset($this->actions[$key]) ? __($this->actions[$key]['label']) : $key;
    }

    /**
     * Pour le filtre de l'écran : catégorie traduite => [clé => libellé traduit], triés.
     *
     * @return array<string, array<string, string>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->actions as $key => $action) {
            $groups[__($action['category'])][$key] = __($action['label']);
        }

        ksort($groups);

        return array_map(function (array $items): array {
            asort($items);

            return $items;
        }, $groups);
    }
}
