<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Sélection d'employés d'un magasin (destinataires d'un envoi, valideurs, personnes à notifier…) : liste des candidats
 * à proposer, puis relecture d'une sélection enregistrée. Le service ne sait rien de l'usage qui en est fait ; c'est
 * le composant de vue `UserPicker` qui affiche les candidats, et l'appelant qui décide quoi faire des comptes retenus.
 *
 * On enregistre l'identifiant du compte, jamais son adresse : `resolve()` relit les comptes au moment de l'usage, de
 * sorte qu'un changement d'adresse est suivi et qu'un compte désactivé ou retiré du magasin n'est plus retenu.
 */
final class UserSelection
{
    /** Plafond par défaut d'une sélection, pour éviter un envoi démesuré. */
    public const DEFAULT_MAX = 20;

    public function __construct(
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly UserRepositoryInterface $users,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Employés actifs du magasin, triés par nom.
     *
     * @param string|null $permission Si renseigné, ne garde que les comptes qui détiennent cette permission dans le magasin
     *                                (ex. « planning_check.approve » pour ne proposer que les valideurs).
     * @return list<array{id: int, name: string, email: string, language: string}>
     */
    public function candidates(int $storeId, ?string $permission = null): array
    {
        $list = [];
        foreach ($this->activeMembers($storeId) as $user) {
            if ($permission !== null && !$this->permissions->can($user, $permission, $storeId)) {
                continue;
            }
            $list[] = $this->summary($user);
        }
        usort($list, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    /**
     * Relit une sélection enregistrée : écarte les doublons, les comptes inconnus, inactifs, retirés du magasin ou sans
     * adresse. L'ordre de la sélection est conservé.
     *
     * @param array<int|string> $userIds
     * @return list<array{id: int, name: string, email: string, language: string}>
     */
    public function resolve(int $storeId, array $userIds): array
    {
        $members = [];
        foreach ($this->activeMembers($storeId) as $user) {
            $members[(int) $user['id']] = $user;
        }

        $resolved = [];
        foreach ($this->normalize($userIds) as $id) {
            if (!isset($members[$id]) || trim((string) ($members[$id]['email'] ?? '')) === '') {
                continue;
            }
            $resolved[] = $this->summary($members[$id]);
        }
        return $resolved;
    }

    /**
     * Valide une sélection reçue d'un formulaire.
     *
     * @param array<int|string> $submitted
     * @return array{ids: list<int>, error: string|null} `error` vaut `required`, `max`, `unknown` ou null
     */
    public function validate(int $storeId, array $submitted, bool $required = false, int $max = self::DEFAULT_MAX): array
    {
        $ids = $this->normalize($submitted);

        $allowed = [];
        foreach ($this->activeMembers($storeId) as $user) {
            $allowed[(int) $user['id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($allowed[$id])) {
                return ['ids' => $ids, 'error' => 'unknown'];
            }
        }
        if (count($ids) > $max) {
            return ['ids' => $ids, 'error' => 'max'];
        }
        if ($required && $ids === []) {
            return ['ids' => $ids, 'error' => 'required'];
        }
        return ['ids' => $ids, 'error' => null];
    }

    /**
     * Comptes actifs proposables pour un magasin : ses membres, plus les propriétaires de l'instance (l'Owner n'est
     * généralement membre d'aucun magasin mais doit pouvoir être choisi).
     *
     * @return list<array<string, mixed>>
     */
    private function activeMembers(int $storeId): array
    {
        $ids = [];
        foreach ($this->storeUsers->findByStore($storeId) as $membership) {
            if (!isset($membership['is_active']) || (bool) $membership['is_active']) {
                $ids[(int) $membership['user_id']] = true;
            }
        }
        foreach ($this->permissions->ownerUserIds() as $ownerId) {
            $ids[$ownerId] = true;
        }

        $members = [];
        foreach (array_keys($ids) as $id) {
            $user = $this->users->findById($id);
            if ($user === null || (isset($user['is_active']) && !(bool) $user['is_active'])) {
                continue;
            }
            $members[] = $user;
        }
        return $members;
    }

    /**
     * @param array<int|string> $ids
     * @return list<int> Identifiants entiers positifs, sans doublon, ordre conservé
     */
    private function normalize(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $int = (int) $id;
            if ($int > 0 && !in_array($int, $clean, true)) {
                $clean[] = $int;
            }
        }
        return $clean;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{id: int, name: string, email: string, language: string, owner: bool}
     */
    private function summary(array $user): array
    {
        $name = trim((string) ($user['display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        }
        return [
            'id'       => (int) $user['id'],
            'name'     => $name,
            'email'    => trim((string) ($user['email'] ?? '')),
            'language' => (string) ($user['language'] ?? ''),
            'owner'    => in_array((int) $user['id'], $this->permissions->ownerUserIds(), true),
        ];
    }
}
