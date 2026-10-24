<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\PageOverlay;
use kintai\Core\Container;
use kintai\Core\Services\WidgetGuard;
use kintai\UI\ViewRenderer;

/**
 * Fenêtres par-dessus les pages, déclarées par les bundles actifs (Bundle::pageOverlays(), contrat 1.27). Une seule est
 * rendue : la plus prioritaire parmi celles que leur bundle juge visibles pour cet utilisateur sur cette page.
 */
final class PageOverlays
{
    /**
     * Pages où aucune fenêtre ne s'affiche : connexion, impression, et édition d'un shift (le manager doit pouvoir
     * corriger sans être bloqué).
     */
    public const NEVER_ON = [
        '#^/(login|logout|password|install)(/|$)#',
        '#/print(/|$)#',
        '#/shifts/(create|new|\d+/edit)(/|$)#',
        '#/shifts/[^/]+/edit(/|$)#',
    ];

    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou sans bundle si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    public static function allowedOn(string $path): bool
    {
        foreach (self::NEVER_ON as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fenêtres déclarées, par priorité puis clé. Une clé déjà prise par un autre bundle est ignorée.
     *
     * @return list<PageOverlay>
     */
    public function declared(): array
    {
        $overlays = [];
        foreach ($this->registry?->pageOverlaysByBundle() ?? [] as $declared) {
            foreach ($declared as $overlay) {
                $overlays[$overlay->key] ??= $overlay;
            }
        }
        $overlays = array_values($overlays);
        usort($overlays, static fn(PageOverlay $a, PageOverlay $b): int => [$a->priority, $a->key] <=> [$b->priority, $b->key]);
        return $overlays;
    }

    /**
     * Contenu de la fenêtre à afficher, ou null. Chaque étape est isolée : une exception dans `visibleWhen`, `data` ou la
     * vue retire cette fenêtre (journalisé) et la suivante est essayée.
     *
     * @param array<string, mixed>     $context Voir PageOverlay::$visibleWhen
     * @param callable(string): bool   $can     Permission accordée (portée globale ou sur un magasin) ?
     * @param callable(?string): bool  $feat    Fonctionnalité par magasin active ?
     * @return array{key: string, html: string, dismissible: bool, session_only: bool, placement: string}|null
     */
    public function render(array $context, ViewRenderer $view, callable $can, callable $feat): ?array
    {
        if (!self::allowedOn((string) ($context['path'] ?? ''))) {
            return null;
        }

        foreach ($this->declared() as $overlay) {
            if (($overlay->permission !== null && !$can($overlay->permission)) || ($overlay->storeFeature !== null && !$feat($overlay->storeFeature))) {
                continue;
            }
            $visible = WidgetGuard::run('overlay:' . $overlay->key, static fn(): bool => $overlay->visibleWhen === null || (bool) ($overlay->visibleWhen)($context), false);
            if (!$visible) {
                continue;
            }
            $html = WidgetGuard::run('overlay:' . $overlay->key, static function () use ($overlay, $context, $view): string {
                $data = $overlay->data !== null ? ($overlay->data)($context) : [];
                if (!is_array($data)) {
                    throw new \UnexpectedValueException('les données de la fenêtre doivent être un tableau');
                }
                $level = ob_get_level();
                try {
                    return $view->renderPartial($overlay->view, $data + [
                        'overlay_key'      => $overlay->key,
                        'overlay_title_id' => 'page-overlay-' . $overlay->key . '-title',
                    ]);
                } finally {
                    // Une vue qui lève une exception laisse son tampon ouvert : on le referme sans l'afficher.
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }
                }
            }, '');
            if ($html !== '') {
                return ['key' => $overlay->key, 'html' => $html, 'dismissible' => $overlay->dismissible, 'session_only' => $overlay->oncePerSession, 'placement' => $overlay->placement];
            }
        }
        return null;
    }
}
