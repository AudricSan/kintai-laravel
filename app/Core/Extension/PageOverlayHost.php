<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\UI\ViewRenderer;

/**
 * Zone « fenêtres de page » du layout (Bundle::pageOverlays()) : construit le contexte de l'utilisateur, demande à
 * PageOverlays la fenêtre à afficher et l'enveloppe dans le dialogue accessible dont public/assets/js/modules/page-overlay.js
 * s'occupe (focus, Échap, fermeture). Ne lève jamais : sans fenêtre, ou en cas de panne, la page s'affiche normalement.
 */
final class PageOverlayHost
{
    /**
     * @param array<string, mixed>   $authUser      utilisateur connecté
     * @param list<string>|null      $storeFeatures fonctionnalités activées du magasin courant (null = toutes)
     * @param ?PageOverlays          $overlays      remplacé en test
     */
    public static function html(array $authUser, string $path, ?array $storeFeatures, ?PageOverlays $overlays = null, ?\DateTimeImmutable $now = null): string
    {
        $userId = (int) ($authUser['id'] ?? 0);
        if ($userId <= 0 || !PageOverlays::allowedOn($path)) {
            return '';
        }

        try {
            $container   = Container::getInstance();
            $permissions = $container->make(PermissionService::class);
            $now       ??= new \DateTimeImmutable('now');
            $storeIds    = array_map(
                static fn(array $m): int => (int) $m['store_id'],
                $container->make(StoreUserRepositoryInterface::class)->findByUser($userId),
            );

            // Permission accordée : portée globale, ou sur au moins un des magasins de l'utilisateur.
            $can = static function (string $permission, ?int $storeId = null) use ($permissions, $authUser, $storeIds): bool {
                if ($storeId !== null) {
                    return $permissions->can($authUser, $permission, $storeId);
                }
                if ($permissions->can($authUser, $permission, null)) {
                    return true;
                }
                foreach ($storeIds as $id) {
                    if ($permissions->can($authUser, $permission, $id)) {
                        return true;
                    }
                }
                return false;
            };
            $context = [
                'user'      => $authUser,
                'now'       => $now,
                'today'     => $now->format('Y-m-d'),
                'path'      => $path,
                'store_ids' => $storeIds,
                'can'       => $can,
            ];

            $rendered = ($overlays ?? PageOverlays::current())->render(
                $context,
                $container->make(ViewRenderer::class),
                static fn(string $permission): bool => $can($permission),
                static fn(string $feature): bool => $storeFeatures === null || in_array($feature, $storeFeatures, true),
            );
        } catch (\Throwable) {
            return '';
        }
        if ($rendered === null) {
            return '';
        }

        $bar = $rendered['placement'] === 'bar';
        return '<div class="page-overlay' . ($bar ? ' page-overlay--bar' : '') . '" id="page-overlay-' . htmlspecialchars($rendered['key']) . '"'
            . ($bar ? ' role="region"' : ' role="dialog" aria-modal="true"')
            . ' aria-labelledby="page-overlay-' . htmlspecialchars($rendered['key']) . '-title" data-page-overlay data-placement="' . ($bar ? 'bar' : 'dialog') . '"'
            . ' data-overlay-key="' . htmlspecialchars($rendered['key']) . '"'
            . ' data-dismissible="' . ($rendered['dismissible'] ? '1' : '0') . '" data-once="' . ($rendered['session_only'] ? '1' : '0') . '">'
            . '<div class="page-overlay__panel">' . $rendered['html'] . '</div></div>';
    }
}
