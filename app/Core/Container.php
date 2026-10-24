<?php

declare(strict_types=1);

namespace kintai\Core;

use Closure;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\BindingResolutionException;
use kintai\Foundation\AliasAwareContainer;
use RuntimeException;

/**
 * Conteneur d'injection de Kintai, adossé à celui de Laravel.
 *
 * L'API reste celle de Kintai (bind, singleton, instance, has, make), utilisée par le cœur et par les bundles
 * (kintai\Contract\Container). Les liaisons sont stockées dans un conteneur Illuminate : dans l'application web, c'est
 * l'application Laravel elle-même (voir {@see self::setInstance()}), si bien qu'un service enregistré d'un côté se
 * résout de l'autre (app(...), injection dans les contrôleurs Laravel…). Un conteneur créé avec `new Container()`
 * garde son propre conteneur Illuminate, vide, comme avant.
 */
final class Container
{
    private static ?Container $instance = null;

    private IlluminateContainer $laravel;

    public function __construct(?IlluminateContainer $laravel = null)
    {
        $this->laravel = $laravel ?? new AliasAwareContainer();
    }

    public static function getInstance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Remplace le conteneur global (null : un nouveau sera créé au prochain getInstance()). */
    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /** Le conteneur Illuminate qui porte les liaisons. */
    public function laravel(): IlluminateContainer
    {
        return $this->laravel;
    }

    public function bind(string $abstract, Closure $factory): void
    {
        $this->laravel->bind($abstract, fn() => $factory($this));
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->laravel->singleton($abstract, fn() => $factory($this));
    }

    public function instance(string $abstract, object $object): void
    {
        $this->laravel->instance($abstract, $object);
    }

    /** Retire la liaison (instance, singleton ou fabrique) : le nom est de nouveau résolu par réflexion. */
    public function forget(string $abstract): void
    {
        $this->laravel->offsetUnset($abstract);
    }

    public function has(string $abstract): bool
    {
        if ($this->laravel->bound($abstract)) {
            return true;
        }
        // Alias du contrat de bundle : la liaison existe sous le nom réel.
        $canonical = self::canonicalName($abstract);
        return $canonical !== $abstract && $this->has($canonical);
    }

    /**
     * @template T
     * @param class-string<T>|string $abstract
     * @return T
     */
    public function make(string $abstract): mixed
    {
        // Alias (noms du contrat de bundle, kintai\Contract\…) : les liaisons sont enregistrées sous le nom réel de la
        // classe ; un bundle qui demande l'alias doit obtenir la même instance, y compris pour les dépendances.
        if (!$this->laravel->bound($abstract)) {
            $abstract = self::canonicalName($abstract);
        }

        try {
            return $this->laravel->make($abstract);
        } catch (BindingResolutionException $e) {
            throw new RuntimeException("Cannot resolve [{$abstract}]: {$e->getMessage()}", 0, $e);
        }
    }

    /** Nom réel d'une classe ou interface désignée par un alias (class_alias), ou le nom tel quel. */
    public static function canonicalName(string $abstract): string
    {
        if (!class_exists($abstract) && !interface_exists($abstract)) {
            return $abstract;
        }
        return (new \ReflectionClass($abstract))->getName();
    }
}
