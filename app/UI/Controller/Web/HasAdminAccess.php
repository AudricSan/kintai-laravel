<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Request;
use kintai\Core\Exceptions\ForbiddenException;

trait HasAdminAccess
{
    protected function managedIds(Request $request): ?array
    {
        return $request->getAttribute('managed_store_ids');
    }

    protected function availableStores(?array $managedIds): array
    {
        $all = $this->stores->findAll();
        if ($managedIds === null) {
            return $all;
        }
        return array_values(array_filter($all, fn($s) => in_array((int) $s['id'], $managedIds, true)));
    }

    protected function assertStoreAccess(Request $request, int $storeId): void
    {
        $managedIds = $request->getAttribute('managed_store_ids');
        if ($managedIds !== null && !in_array($storeId, $managedIds, true)) {
            throw new ForbiddenException(__('error_not_store_manager'));
        }
    }

    /**
     * Variante multi-store d'assertStoreAccess : autorise l'accès dès que
     * l'utilisateur gère au moins un des stores donnés (ex. un type de shift
     * activé sur plusieurs stores, une ressource rattachée à plusieurs stores).
     */
    protected function assertAnyStoreAccess(Request $request, array $storeIds): void
    {
        $managedIds = $request->getAttribute('managed_store_ids');
        if ($managedIds === null) {
            return;
        }
        foreach ($storeIds as $storeId) {
            if (in_array((int) $storeId, $managedIds, true)) {
                return;
            }
        }
        throw new ForbiddenException(__('error_not_resource_store_manager'));
    }

    protected function memberUserIds(array $managedIds): array
    {
        $ids = [];
        foreach ($managedIds as $storeId) {
            foreach ($this->storeUsers->findByStore($storeId) as $m) {
                $ids[] = (int) $m['user_id'];
            }
        }
        return array_unique($ids);
    }

    /**
     * Identifiants des membres d'un magasin encore à afficher dans un planning : un compte supprimé (anonymisé,
     * deleted_at), désactivé (is_active = 0) ou absent de la base n'a plus de fiche à ouvrir (404) et ne doit plus
     * encombrer la liste des shifts sous la forme d'un « #90 » sans nom.
     *
     * @return list<int>
     */
    protected function activeMemberIds(int $storeId): array
    {
        $active = [];
        foreach ($this->users->findAll() as $u) {
            if (empty($u['deleted_at']) && (int) ($u['is_active'] ?? 1) === 1) {
                $active[(int) $u['id']] = true;
            }
        }

        $ids = [];
        foreach ($this->storeUsers->findByStore($storeId) as $m) {
            $uid = (int) $m['user_id'];
            if (isset($active[$uid])) {
                $ids[$uid] = $uid;
            }
        }
        return array_values($ids);
    }

    protected function base(): string
    {
        $sn   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($sn)), '/');
        return ($base === '.' || $base === '/') ? '' : $base;
    }

    protected function filterByStore(array $items, ?array $managedIds, string $key = 'store_id'): array
    {
        if ($managedIds === null) {
            return $items;
        }
        return array_values(array_filter(
            $items,
            fn($i) => in_array((int) ($i[$key] ?? 0), $managedIds, true)
        ));
    }

    protected function buildUsersMap(): array
    {
        $map = [];
        foreach ($this->users->findAll() as $u) {
            $fullName = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
            $map[(int) $u['id']] = $fullName ?: ($u['display_name'] ?? $u['email'] ?? '#' . $u['id']);
        }
        return $map;
    }

    protected function buildStoresMap(?array $managedIds): array
    {
        $map = [];
        foreach ($this->availableStores($managedIds) as $s) {
            $map[(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }
        return $map;
    }
}
