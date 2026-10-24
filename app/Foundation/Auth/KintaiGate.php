<?php

declare(strict_types=1);

namespace kintai\Foundation\Auth;

use kintai\Core\Auth\PermissionCatalog;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;

/**
 * Les permissions RBAC de Kintai dans le Gate de Laravel : Gate::allows('shifts.update', $storeId),
 * $user->can(...) et @can('shifts.update', $storeId) répondent comme PermissionService::can().
 *
 * Seules les clés du catalogue (PermissionCatalog, bundles actifs compris) sont prises en charge ; toute autre
 * capacité est laissée aux règles Laravel éventuellement définies.
 */
final class KintaiGate
{
    /** @param array<int, mixed> $arguments [identifiant du magasin] facultatif */
    public static function before(mixed $user, string $ability, array $arguments = []): ?bool
    {
        if (!$user instanceof KintaiUser || !PermissionCatalog::exists($ability)) {
            return null;
        }

        $storeId = $arguments[0] ?? null;

        return Container::getInstance()->make(PermissionService::class)
            ->can($user->toArray(), $ability, is_numeric($storeId) ? (int) $storeId : null);
    }
}
