<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Noms d'employés et de magasins pour les lignes d'export, relus une seule fois par identifiant : un export de plusieurs
 * milliers de lignes ne doit pas faire autant de lectures de la table users.
 */
final class ExportDirectory
{
    /** @var array<int, array<string, mixed>|null> */
    private array $users = [];
    /** @var array<int, array<string, mixed>|null> */
    private array $stores = [];

    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly StoreRepositoryInterface $storeRepo,
    ) {}

    /** @return array<string, mixed>|null */
    public function user(int $id): ?array
    {
        return array_key_exists($id, $this->users) ? $this->users[$id] : $this->users[$id] = $this->userRepo->findById($id);
    }

    /** @return array<string, mixed>|null */
    public function store(int $id): ?array
    {
        return array_key_exists($id, $this->stores) ? $this->stores[$id] : $this->stores[$id] = $this->storeRepo->findById($id);
    }

    public function userName(int $id): string
    {
        $user = $this->user($id);
        if ($user === null) {
            return '';
        }
        $name = trim((string) ($user['display_name'] ?? ''));

        return $name !== '' ? $name : trim((string) ($user['last_name'] ?? '') . ' ' . (string) ($user['first_name'] ?? ''));
    }

    public function storeName(int $id): string
    {
        return (string) ($this->store($id)['name'] ?? '');
    }
}
