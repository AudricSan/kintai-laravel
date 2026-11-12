<?php

namespace App\Stores;

use InvalidArgumentException;
use Kintai\Contracts\Stores\StoreFeatureRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultStoreFeatureRegistry implements StoreFeatureRegistry
{
    /** @var array<string, string> clé => libellé */
    private array $features = [];

    public function register(string $key, string $label): void
    {
        if (isset($this->features[$key])) {
            throw new InvalidArgumentException("Store feature [{$key}] is already registered.");
        }

        $this->features[$key] = $label;
    }

    public function has(string $key): bool
    {
        return isset($this->features[$key]);
    }

    /**
     * @return array<string, string> clé => libellé, dans l'ordre d'enregistrement
     */
    public function all(): array
    {
        return $this->features;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->features);
    }
}
