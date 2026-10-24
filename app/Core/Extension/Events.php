<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Annonce des événements du Core aux bundles actifs qui les écoutent (point d'extension Bundle::eventListeners(),
 * contrat 1.13). Un auditeur qui lève une exception est journalisé et n'empêche ni les autres auditeurs ni l'action du
 * Core qui a déclenché l'événement.
 */
final class Events
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucun auditeur si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /** Transmet l'événement à chaque bundle actif qui l'écoute. */
    public function dispatch(object $event): void
    {
        foreach ($this->registry?->eventListenersByBundle() ?? [] as $slug => $listeners) {
            $listener = $listeners[$event::class] ?? null;
            if ($listener === null) {
                continue;
            }
            try {
                $listener($event);
            } catch (\Throwable $e) {
                Log::warning('bundle_event_listener_failed', ['bundle' => $slug, 'event' => $event::class, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Le bundle actif $slug écoute-t-il cet événement ? */
    public function isListenedBy(string $slug, string $eventClass): bool
    {
        return isset(($this->registry?->eventListenersByBundle() ?? [])[$slug][$eventClass]);
    }
}
