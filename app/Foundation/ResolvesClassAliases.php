<?php

declare(strict_types=1);

namespace kintai\Foundation;

/**
 * Règles de résolution de l'ancien conteneur Kintai, appliquées au conteneur Illuminate.
 *
 * - Alias de classe (class_alias) : le contrat de bundle expose les classes du cœur sous des alias
 *   (kintai\Contract\…, voir app/Contract/surface.php) ; les liaisons, elles, sont enregistrées sous le nom réel. Un
 *   bundle qui demande l'alias, directement ou par le type d'un paramètre de constructeur, obtient la même liaison et
 *   la même instance.
 * - Paramètre typé par une classe avec valeur par défaut (`?InstanceLocale $locale = null`) : Kintai injecte la classe
 *   dès qu'elle se construit, et ne garde la valeur par défaut que sinon. Laravel garde la valeur par défaut dès que la
 *   classe n'est pas liée explicitement, ce qui privait de nombreux services de leurs dépendances optionnelles.
 */
trait ResolvesClassAliases
{
    /** @var array<string, string> */
    private array $classAliasCache = [];

    public function getAlias($abstract)
    {
        $resolved = parent::getAlias($abstract);
        if ($resolved !== $abstract || isset($this->bindings[$abstract]) || isset($this->instances[$abstract])) {
            return $resolved;
        }

        return $this->classAliasCache[$abstract] ??= (class_exists($abstract) || interface_exists($abstract))
            ? (new \ReflectionClass($abstract))->getName()
            : $abstract;
    }

    protected function resolveClass(\ReflectionParameter $parameter, ?string $className = null)
    {
        if (!$parameter->isDefaultValueAvailable() || $parameter->isVariadic()) {
            return parent::resolveClass($parameter, $className);
        }

        $depth = count($this->with);
        try {
            return $this->make($className ?? \Illuminate\Container\Util::getParameterClassName($parameter));
        } catch (\Illuminate\Contracts\Container\BindingResolutionException) {
            // make() n'a pas dépilé les paramètres de la résolution interrompue.
            $this->with = array_slice($this->with, 0, $depth);

            return $parameter->getDefaultValue();
        }
    }
}
