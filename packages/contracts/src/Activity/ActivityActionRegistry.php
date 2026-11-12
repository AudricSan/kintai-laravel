<?php

namespace Kintai\Contracts\Activity;

/**
 * Point d'extension : le Core et les bundles y déclarent le libellé de leurs actions de journal.
 *
 * Un bundle s'y enregistre depuis son fournisseur de services, au démarrage :
 *
 *     app(ActivityActionRegistry::class)->register('timeoff.approved', 'Time off approved', 'Time off');
 *
 * Le libellé et la catégorie sont des clés de traduction, traduites à l'affichage. Une action absente du registre
 * s'affiche par sa clé brute.
 */
interface ActivityActionRegistry
{
    /**
     * @throws \InvalidArgumentException Action déjà enregistrée.
     */
    public function register(string $key, string $label, string $category): void;

    public function has(string $key): bool;

    /**
     * @return array<string, array{label: string, category: string}>
     */
    public function all(): array;

    /**
     * Libellé traduit, ou la clé brute pour une action inconnue.
     */
    public function label(string $key): string;

    /**
     * Pour le filtre de l'écran : catégorie traduite => [clé => libellé traduit], triés.
     *
     * @return array<string, array<string, string>>
     */
    public function grouped(): array;
}
