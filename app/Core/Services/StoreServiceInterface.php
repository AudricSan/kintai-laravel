<?php

declare(strict_types=1);

namespace kintai\Core\Services;

interface StoreServiceInterface
{
    public function getStoresForAdmin(?array $managedIds, string $sort = 'name_asc'): array;

    public function getStoreMembers(int $storeId): array;

    public function getStoreMembersMap(int $storeId): array;

    public function createStore(array $data): array;

    public function updateStore(int $storeId, array $data): array;

    public function deleteStore(int $storeId): void;

    public function addMember(int $storeId, int $userId): ?array;

    public function removeMember(int $membershipId, int $storeId): void;

    public function saveMemberDeductions(int $membershipId, int $storeId, bool $subjectTo): void;

    public function getDeductionSettings(int $storeId): array;

    public function getDeductionOverrides(int $membershipId): array;
}
