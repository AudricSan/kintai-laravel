<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;

final class StoreUserController
{
    public function __construct(
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly StoreRepositoryInterface $stores,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
        // Facultatif pour les tests qui construisent le contrôleur à la main : sans lui, personne n'est Owner.
        private readonly ?OwnerGuard $ownerGuard = null,
    ) {}

    /** Un gestionnaire non-Owner ne désactive ni ne supprime sa propre adhésion (voir OwnerGuard). */
    private function assertNotSelf(Request $request, int $targetUserId): void
    {
        $callerId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        if ($this->ownerGuard !== null) {
            $this->ownerGuard->assertNotSelfUnlessOwner($callerId, $targetUserId);
        } elseif ($callerId === $targetUserId) {
            throw new ForbiddenException(__('error_cannot_act_on_self'));
        }
    }

    /** GET /api/v1/stores/{store_id}/members */
    public function index(Request $request): Response
    {
        $storeId = (int) $request->param('store_id');
        $this->requireStore($storeId);
        return Response::json($this->storeUsers->findByStore($storeId));
    }

    /** GET /api/v1/stores/{store_id}/members/{id} */
    public function show(Request $request): Response
    {
        $storeId = (int) $request->param('store_id');
        $id      = (int) $request->param('id');
        $this->requireStore($storeId);

        $member = $this->storeUsers->findById($id);
        if ($member === null || (int) $member['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }

        return Response::json($member);
    }

    /** POST /api/v1/stores/{store_id}/members */
    public function store(Request $request): Response
    {
        $storeId = (int) $request->param('store_id');
        $this->requireStore($storeId);
        $data   = array_merge($this->allowedFields($request->json() ?? []), ['store_id' => $storeId]);

        // `user_id` obligatoire, existant et pas déjà membre : sans cela un POST
        // créait des lignes orphelines ou en double.
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0 || $this->users->findById($userId) === null) {
            throw new ValidationException(['user_id' => __('error_user_not_found')]);
        }
        if ($this->storeUsers->findMembership($storeId, $userId) !== null) {
            throw new ValidationException(['user_id' => __('error_api_member_already_exists')]);
        }

        $saved  = $this->storeUsers->save($data);
        $this->auditLogger->log($request, 'store_user.created', 'store_user', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: $storeId);
        return Response::json($saved, 201);
    }

    /** PUT /api/v1/stores/{store_id}/members/{id} */
    public function update(Request $request): Response
    {
        $storeId = (int) $request->param('store_id');
        $id      = (int) $request->param('id');
        $this->requireStore($storeId);

        $existing = $this->storeUsers->findById($id);
        if ($existing === null || (int) $existing['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }

        // `user_id` et `store_id` sont immuables : une adhésion ne change ni de personne ni de magasin.
        $data = $this->allowedFields($request->json() ?? []);
        unset($data['user_id']);
        if (array_key_exists('is_active', $data) && empty($data['is_active']) && !empty($existing['is_active'] ?? 1)) {
            $this->assertNotSelf($request, (int) $existing['user_id']);
        }
        return Response::json($this->storeUsers->save(array_merge($existing, $data, ['id' => $id, 'store_id' => $storeId])));
    }

    /**
     * Liste blanche des champs modifiables. Le rôle réel d'un membre se gère
     * exclusivement via /admin/roles et role_assignments (RoleAssignmentSyncService)
     * — jamais par cette API générique, pour ne pas désynchroniser l'autorisation
     * réelle. `id`, `store_id`, `role` et `is_manager` sont donc ignorés : un `id`
     * dans le corps d'un POST réécrivait l'adhésion d'un autre magasin.
     */
    private function allowedFields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'user_id', 'staff_code', 'hire_date', 'contract_type', 'hourly_rates',
            'is_active', 'deduction_overrides', 'subject_to_deductions',
        ]));
    }

    /** DELETE /api/v1/stores/{store_id}/members/{id} */
    public function destroy(Request $request): Response
    {
        $storeId = (int) $request->param('store_id');
        $id      = (int) $request->param('id');
        $this->requireStore($storeId);

        $existing = $this->storeUsers->findById($id);
        if ($existing === null || (int) $existing['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }

        $this->assertNotSelf($request, (int) $existing['user_id']);

        $this->storeUsers->delete($id);
        $this->auditLogger->log($request, 'store_user.deleted', 'store_user', resourceId: $id, storeId: $storeId);
        return Response::empty();
    }

    private function requireStore(int $id): void
    {
        if ($this->stores->findById($id) === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
    }
}
