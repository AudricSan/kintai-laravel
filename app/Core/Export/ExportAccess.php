<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Extension\ExportDataset;
use kintai\Contract\Provider\ExportQuery;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;

/**
 * Décide ce qu'un utilisateur peut exporter et en fait un périmètre sûr (ExportQuery). C'est ici, et nulle part ailleurs, que
 * les droits sont vérifiés : les fournisseurs de lignes, y compris ceux des bundles, ne reçoivent que le résultat.
 *
 * Règles :
 * - exporter les données des autres exige la permission du jeu de données dans chaque magasin exporté (rôle de portée
 *   magasin ou globale) ;
 * - « mes données » (SCOPE_ME) n'exige rien, mais seulement pour les jeux qui le permettent (selfService) ;
 * - les personnes demandées sont toujours recoupées avec les membres des magasins autorisés : un identifiant glissé dans la
 *   requête ne donne jamais accès à quelqu'un d'un autre magasin.
 */
final class ExportAccess
{
    public const SCOPE_ME    = 'me';
    public const SCOPE_STORE = 'store';
    public const SCOPE_ALL   = 'all';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly StoreRepositoryInterface $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
    ) {}

    /**
     * Magasins actifs où l'utilisateur détient la permission.
     *
     * @param array<string, mixed> $user
     * @return list<int>
     */
    public function permittedStoreIds(array $user, string $permission): array
    {
        $ids = [];
        foreach ($this->stores->findActive() as $store) {
            if ($this->permissions->can($user, $permission, (int) $store['id'])) {
                $ids[] = (int) $store['id'];
            }
        }

        return $ids;
    }

    /** @return list<int> Magasins dont l'utilisateur est membre */
    public function ownStoreIds(int $userId): array
    {
        return array_values(array_unique(array_map(
            static fn(array $m): int => (int) $m['store_id'],
            $this->storeUsers->findByUser($userId)
        )));
    }

    /**
     * Jeux de données que l'utilisateur peut exporter, avec ce qu'il peut en faire.
     *
     * @param array<string, mixed>          $user
     * @param array<string, ExportDataset>  $datasets
     * @return array<string, array{dataset: ExportDataset, stores: list<int>, self: bool}>
     */
    public function available(array $user, array $datasets): array
    {
        $own = $this->ownStoreIds((int) $user['id']);
        $out = [];
        foreach ($datasets as $key => $dataset) {
            $stores = $this->permittedStoreIds($user, $dataset->permission);
            $self   = $dataset->selfService && $own !== [];
            if ($stores !== [] || $self) {
                $out[$key] = ['dataset' => $dataset, 'stores' => $stores, 'self' => $self];
            }
        }

        return $out;
    }

    /**
     * Périmètre autorisé d'un export.
     *
     * @param array<string, mixed> $user
     * @param list<int>            $requestedUserIds Personnes cochées (vide = tout le monde)
     * @throws ForbiddenException si le périmètre demandé n'est pas permis
     */
    public function query(
        array $user,
        ExportDataset $dataset,
        string $scope,
        ?int $storeId,
        array $requestedUserIds,
        string $from,
        string $to,
        string $locale = 'fr',
    ): ExportQuery {
        $userId = (int) $user['id'];

        if ($scope === self::SCOPE_ME) {
            $own = $this->ownStoreIds($userId);
            if (!$dataset->selfService || $own === []) {
                throw new ForbiddenException(__('export_forbidden'));
            }

            return new ExportQuery($own, [$userId], $from, $to, $locale);
        }

        $permitted = $this->permittedStoreIds($user, $dataset->permission);
        if ($scope === self::SCOPE_STORE) {
            if ($storeId === null || !in_array($storeId, $permitted, true)) {
                throw new ForbiddenException(__('export_forbidden'));
            }
            $stores = [$storeId];
        } elseif ($scope === self::SCOPE_ALL) {
            $stores = $permitted;
        } else {
            throw new ForbiddenException(__('export_forbidden'));
        }
        if ($stores === []) {
            throw new ForbiddenException(__('export_forbidden'));
        }

        return new ExportQuery($stores, $dataset->perPerson ? $this->members($stores, $requestedUserIds) : null, $from, $to, $locale);
    }

    /**
     * Recoupe les personnes demandées avec les membres des magasins autorisés. Aucune personne demandée = tout le monde (null) ;
     * des personnes demandées mais aucune valable = liste vide, donc un export vide plutôt que tous les membres.
     *
     * @param list<int> $storeIds
     * @param list<int> $requested
     * @return list<int>|null
     */
    private function members(array $storeIds, array $requested): ?array
    {
        $requested = array_values(array_unique(array_filter(array_map('intval', $requested), static fn(int $id): bool => $id > 0)));
        if ($requested === []) {
            return null;
        }

        $members = [];
        foreach ($storeIds as $storeId) {
            foreach ($this->storeUsers->findByStore($storeId) as $m) {
                $members[(int) $m['user_id']] = true;
            }
        }

        return array_values(array_filter($requested, static fn(int $id): bool => isset($members[$id])));
    }
}
