<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\Action;
use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Liens d'action des bundles dans les emplacements des pages du Core, construits à partir de données : ceux déclarés
 * par les bundles actifs (point d'extension Bundle::actions(), contrat 1.6). Le Core n'en ajoute aucun de lui-même.
 */
final class Actions
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucune action si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /**
     * Actions de l'emplacement déclarées par les bundles actifs, triées par ordre.
     * Une clé déjà présente dans l'emplacement n'est pas reprise.
     *
     * @return list<Action>
     */
    public function inSlot(string $slot): array
    {
        $all = [];
        foreach ($this->registry?->actionsByBundle() ?? [] as $declared) {
            array_push($all, ...$declared);
        }

        $actions = [];
        foreach ($all as $action) {
            if ($action->slot === $slot) {
                $actions[$action->key] ??= $action;
            }
        }
        $actions = array_values($actions);
        usort($actions, static fn(Action $a, Action $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        return $actions;
    }

    /**
     * Liens prêts à afficher pour cet emplacement et ce contexte. Une action dont l'adresse lève une exception est
     * retirée (journalisée).
     *
     * @param array<string, mixed>     $context
     * @param callable(string): bool   $can
     * @param callable(?string): bool  $feat
     * @return list<array{key: string, href: string, label: string, icon: string, style: string}>
     */
    public function links(string $slot, array $context, callable $can, ?callable $feat = null): array
    {
        $links = [];
        foreach ($this->inSlot($slot) as $action) {
            if (($action->permission !== null && !$can($action->permission))
                || ($action->storeFeature !== null && $feat !== null && !$feat($action->storeFeature))) {
                continue;
            }
            try {
                $href = ($action->url)($context);
            } catch (\Throwable $e) {
                try {
                    Log::warning('bundle_extension_failed', ['point' => 'actions', 'key' => $action->key, 'error' => $e->getMessage()]);
                } catch (\Throwable) {
                }
                continue;
            }
            if (!is_string($href) || $href === '') {
                continue;
            }
            $links[] = ['key' => $action->key, 'href' => $href, 'label' => __($action->label), 'icon' => $action->icon, 'style' => $action->style];
        }
        return $links;
    }
}
