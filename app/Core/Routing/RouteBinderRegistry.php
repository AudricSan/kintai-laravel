<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

/**
 * Types de paramètres de route disponibles dans les motifs ({id:store}, {uid:employee}), pour le Core comme
 * pour les bundles. Les binders sont construits à la première utilisation.
 */
final class RouteBinderRegistry
{
    /** @var array<string, callable(): RouteParamBinder> */
    private array $factories = [];

    /** @var array<string, RouteParamBinder> */
    private array $binders = [];

    /** @param callable(): RouteParamBinder $factory */
    public function register(string $type, callable $factory): void
    {
        $this->factories[$type] = $factory;
        unset($this->binders[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    public function get(string $type): RouteParamBinder
    {
        if (!isset($this->factories[$type])) {
            throw new \InvalidArgumentException("Type de paramètre de route inconnu : [{$type}].");
        }

        return $this->binders[$type] ??= ($this->factories[$type])();
    }

    /** Vide les caches des binders déjà construits (après une écriture qui change un alias). */
    public function reset(): void
    {
        foreach ($this->binders as $binder) {
            if (method_exists($binder, 'reset')) {
                $binder->reset();
            }
        }
    }
}
