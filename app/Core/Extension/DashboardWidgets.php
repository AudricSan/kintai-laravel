<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\DashboardWidget;
use kintai\Core\Container;
use kintai\Core\Services\WidgetGuard;
use kintai\UI\ViewRenderer;

/**
 * Widgets des tableaux de bord (gérant et employé), construits à partir de données :
 * - ceux du Core, rendus par la vue du tableau de bord ;
 * - ceux déclarés par les bundles actifs (point d'extension Bundle::dashboardWidgets(), contrat 1.4), rendus ici à
 *   partir de la vue du bundle et affichés après ceux du Core.
 */
final class DashboardWidgets
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou Core seul si indisponible. */
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
     * Widgets déclarés par les bundles actifs pour ce public, triés par ordre. Une clé déjà prise (widget du Core ou
     * d'un autre bundle) est ignorée.
     *
     * @param list<string> $coreKeys Widgets du Core pour ce public
     * @return list<DashboardWidget>
     */
    public function declared(string $audience, array $coreKeys): array
    {
        $widgets = [];
        foreach ($this->registry?->dashboardWidgetsByBundle() ?? [] as $declared) {
            foreach ($declared as $widget) {
                if ($widget->audience !== $audience || isset($widgets[$widget->key])) {
                    continue;
                }
                if (in_array($widget->key, $coreKeys, true)) {
                    continue;
                }
                $widgets[$widget->key] = $widget;
            }
        }
        $widgets = array_values($widgets);
        usort($widgets, static fn(DashboardWidget $a, DashboardWidget $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        return $widgets;
    }

    /**
     * Clés de tous les widgets proposés pour ce public : ceux du Core, puis ceux déclarés par les bundles.
     *
     * @param list<string> $coreKeys
     * @return list<string>
     */
    public function keys(string $audience, array $coreKeys): array
    {
        $keys = array_values($coreKeys);
        foreach ($this->declared($audience, $coreKeys) as $widget) {
            if (!in_array($widget->key, $keys, true)) {
                $keys[] = $widget->key;
            }
        }
        return $keys;
    }

    /**
     * Rend les widgets de bundles activés et visibles. Chaque widget est isolé : une exception dans ses données ou sa
     * vue le retire (journalisé), les autres s'affichent.
     *
     * @param list<string>             $coreKeys
     * @param list<string>             $enabledKeys Widgets activés par l'utilisateur
     * @param array<string, mixed>     $context     Voir DashboardWidget::$data
     * @param callable(string): bool   $can         Permission accordée ?
     * @param callable(?string): bool  $feat        Fonctionnalité par magasin active ?
     * @return list<array{key: string, html: string}>
     */
    public function render(string $audience, array $coreKeys, array $enabledKeys, array $context, ViewRenderer $view, callable $can, callable $feat): array
    {
        $rendered = [];
        foreach ($this->declared($audience, $coreKeys) as $widget) {
            if (!in_array($widget->key, $enabledKeys, true) || !$this->isVisible($widget, $can, $feat)) {
                continue;
            }
            $html = WidgetGuard::run($widget->key, static function () use ($widget, $context, $view): string {
                $data = $widget->data !== null ? ($widget->data)($context) : [];
                if (!is_array($data)) {
                    throw new \UnexpectedValueException('les données du widget doivent être un tableau');
                }
                $level = ob_get_level();
                try {
                    return $view->renderPartial($widget->view, $data + ['widget_key' => $widget->key]);
                } finally {
                    // Une vue qui lève une exception laisse son tampon ouvert : on le referme sans l'afficher.
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }
                }
            }, '');
            if ($html !== '') {
                $rendered[] = ['key' => $widget->key, 'html' => $html];
            }
        }
        return $rendered;
    }

    /**
     * Tailles des widgets de bundles pour ce public : largeur par défaut (colonnes), hauteur par défaut (lignes) et
     * bornes de largeur. Un widget sans largeur par défaut garde la pleine largeur d'avant (non listé).
     *
     * @param list<string> $coreKeys
     * @return array{widths: array<string, int>, heights: array<string, int>, limits: array<string, array{0: int, 1: int}>, refresh: array<string, int>}
     */
    public function sizes(string $audience, array $coreKeys): array
    {
        $sizes = ['widths' => [], 'heights' => [], 'limits' => [], 'refresh' => []];
        foreach ($this->declared($audience, $coreKeys) as $widget) {
            if ($widget->defaultWidth !== null) {
                $sizes['widths'][$widget->key] = $widget->defaultWidth;
            }
            if ($widget->defaultHeight > 1) {
                $sizes['heights'][$widget->key] = $widget->defaultHeight;
            }
            $sizes['limits'][$widget->key] = [$widget->minWidth, $widget->maxWidth];
            if ($widget->refreshInterval > 0) {
                $sizes['refresh'][$widget->key] = $widget->refreshInterval;
            }
        }
        return $sizes;
    }

    /**
     * Libellés (traduits), permissions et fonctionnalités des widgets de bundles, pour le panneau « Personnaliser ».
     *
     * @param list<string> $coreKeys
     * @return array{labels: array<string, string>, permissions: array<string, string>, features: array<string, string>}
     */
    public function meta(string $audience, array $coreKeys): array
    {
        $meta = ['labels' => [], 'permissions' => [], 'features' => []];
        foreach ($this->declared($audience, $coreKeys) as $widget) {
            $meta['labels'][$widget->key] = __($widget->label);
            if ($widget->permission !== null) {
                $meta['permissions'][$widget->key] = $widget->permission;
            }
            if ($widget->storeFeature !== null) {
                $meta['features'][$widget->key] = $widget->storeFeature;
            }
        }
        return $meta;
    }

    /**
     * Widgets activés après l'ajout ($add = true) ou le retrait d'un widget proposé. Sans préférences enregistrées
     * (null), la sélection de départ est celle par défaut ($defaults). Les widgets enregistrés mais pas
     * proposés en ce moment (bundle désactivé) sont conservés, pour qu'ils reviennent à sa réactivation.
     *
     * @param list<string>      $defaults Widgets affichés par défaut
     * @param list<string>|null $previous
     * @return list<string>
     */
    public static function toggle(array $defaults, ?array $previous, string $key, bool $add): array
    {
        $enabled = array_values(array_filter(
            $previous ?? $defaults,
            static fn($k): bool => is_string($k) && preg_match(DashboardLayout::KEY, $k) === 1,
        ));
        $enabled = array_values(array_diff($enabled, [$key]));
        if ($add) {
            $enabled[] = $key;
        }
        return array_values(array_unique($enabled));
    }

    /** @param callable(string): bool $can @param callable(?string): bool $feat */
    private function isVisible(DashboardWidget $widget, callable $can, callable $feat): bool
    {
        return ($widget->permission === null || $can($widget->permission))
            && ($widget->storeFeature === null || $feat($widget->storeFeature));
    }
}
