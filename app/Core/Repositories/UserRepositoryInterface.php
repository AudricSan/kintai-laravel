<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

interface UserRepositoryInterface
{
    /**
     * Finds a user by their ID.
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array;

    /**
     * Finds a user by their email address.
     * @param string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array;

    /**
     * Trouve un utilisateur par son code employé.
     */
    public function findByEmployeeCode(string $code): ?array;

    /**
     * Retrieves all users.
     * @return array
     */
    public function findAll(): array;

    /**
     * Compte les utilisateurs actifs (is_active = 1, deleted_at IS NULL).
     */
    public function countActive(): int;

    /**
     * Saves a user record. Creates if no ID, updates if ID exists.
     * @param array $userData
     * @return array The saved user data.
     */
    public function save(array $userData): array;

    /**
     * Deletes a user by their ID.
     * @param int $id
     * @return int The number of deleted users (0 or 1).
     */
    public function delete(int $id): int;
}
