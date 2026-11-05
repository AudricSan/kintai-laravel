<?php

namespace App\Activity;

use InvalidArgumentException;
use Kintai\Contracts\Activity\ActivityActionRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultActivityActionRegistry implements ActivityActionRegistry
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
