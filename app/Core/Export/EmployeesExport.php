<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Repositories\StoreUserRepositoryInterface;

/**
 * Jeu de données « Employés » : une ligne par appartenance (employé × magasin) avec l'identité et les coordonnées.
 * Jamais de mot de passe, de jeton ni de champ libre (bio, loisirs) : seulement ce qu'une fiche employé montre à un gérant.
 */
final class EmployeesExport implements ExportRowsProvider
{
    public function __construct(
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly ExportDirectory $directory,
    ) {}

    public function rows(ExportQuery $query): iterable
    {
        $rows = [];
        foreach ($query->storeIds as $storeId) {
            foreach ($this->storeUsers->findByStore($storeId) as $membership) {
                $userId = (int) ($membership['user_id'] ?? 0);
                $user   = $query->includesUser($userId) ? $this->directory->user($userId) : null;
                if ($user === null || !empty($user['deleted_at'])) {
                    continue;
                }

                $rows[] = [
                    'store'         => $this->directory->storeName($storeId),
                    'employee_id'   => $userId,
                    'last_name'     => (string) ($user['last_name'] ?? ''),
                    'first_name'    => (string) ($user['first_name'] ?? ''),
                    'display_name'  => (string) ($user['display_name'] ?? ''),
                    'email'         => (string) ($user['email'] ?? ''),
                    'phone'         => (string) ($user['phone'] ?? ''),
                    'mobile_phone'  => (string) ($user['mobile_phone'] ?? ''),
                    'postal_code'   => (string) ($user['postal_code'] ?? ''),
                    'address'       => (string) ($user['address'] ?? ''),
                    'language'      => (string) ($user['language'] ?? ''),
                    'active'        => !empty($user['is_active']) && (!isset($membership['is_active']) || !empty($membership['is_active'])),
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$a['store'], $a['last_name'], $a['first_name']] <=> [$b['store'], $b['last_name'], $b['first_name']]);

        return $rows;
    }
}
