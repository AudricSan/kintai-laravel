<?php

namespace Kintai\Contracts\Stores;

/**
 * Point d'extension : le Core et les bundles y déclarent les fonctionnalités activables par magasin.
 * Le libellé est une clé de traduction, traduite à l'affichage.
 */
interface StoreFeatureRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(string $key, string $label): void;

    public function has(string $key): bool;

    /**
     * @return array<string, string> clé => libellé, dans l'ordre d'enregistrement
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function keys(): array;
}
