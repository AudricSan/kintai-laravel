<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Api\Paginator;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;

final class ShiftTypeController
{
    public function __construct(
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/shift-types?store_id=X&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId  = $request->query('store_id');
        $authUser = $this->authUser($request);

        $items = $storeId !== null
            ? $this->shiftTypes->findByStore((int) $storeId)
            : $this->shiftTypes->findAll();
        $items = array_map(fn ($t) => $this->withStoreIds($t), $items);

        // Un type est visible s'il est rattaché à un magasin sur lequel l'appelant a shifts.view.
        if (!$this->permissions->hasUnrestrictedGrant($authUser, 'shifts.view')) {
            $items = array_values(array_filter($items, function (array $t) use ($authUser): bool {
                foreach ($t['store_ids'] as $sid) {
                    if ($this->permissions->can($authUser, 'shifts.view', (int) $sid)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return Response::json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/shift-types/{id} */
    public function show(Request $request): Response
    {
        $type = $this->requireType($request, 'shifts.view');
        return Response::json($this->withStoreIds($type));
    }

    /**
     * POST /api/v1/shift-types
     * `store_id` (unique, rétrocompatible) ou `store_ids` (tableau) — au moins
     * l'un des deux. `store_id` reste lu par ApiPermissionMiddleware pour
     * scoper la vérification de permission à ce store ; chaque magasin demandé
     * est revérifié ici (le middleware ne lit pas `store_ids`).
     */
    public function store(Request $request): Response
    {
        $body     = $request->json() ?? [];
        $storeIds = $this->extractStoreIds($body);
        $data     = PayloadValidator::validate($body, ApiPayloadRules::shiftType());
        $data['code'] = strtoupper($data['code']);

        $this->assertWritableStores($request, $storeIds);

        $saved = $this->shiftTypes->save($data);
        if ($storeIds !== []) {
            $this->shiftTypes->syncStores((int) $saved['id'], $storeIds);
        }

        $this->auditLogger->log($request, 'shift_type.created', 'shift_type', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: $storeIds[0] ?? null);
        return Response::json($this->withStoreIds($saved), 201);
    }

    /**
     * PUT /api/v1/shift-types/{id}
     * `store_id`/`store_ids` absents du corps : les affectations aux stores
     * existantes ne sont pas modifiées (mise à jour partielle).
     */
    public function update(Request $request): Response
    {
        $old      = $this->requireType($request, 'shifts.update');
        $id       = (int) $old['id'];
        $this->assertManagesEveryStore($request, $id);
        $body     = $request->json() ?? [];
        $storeIds = $this->extractStoreIds($body);
        $data     = PayloadValidator::validate($body, ApiPayloadRules::shiftType(), true);
        if ($data === [] && $storeIds === []) {
            throw new ValidationException(['body' => __('val_no_updatable_field')], __('val_no_updatable_field'));
        }
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        if ($storeIds !== []) {
            $this->assertWritableStores($request, $storeIds);
        }

        $saved = $this->shiftTypes->save(array_merge($data, ['id' => $id]));
        if ($storeIds !== []) {
            $this->shiftTypes->syncStores($id, $storeIds);
        }

        $this->auditLogger->logUpdate($request, 'shift_type.updated', 'shift_type', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data, storeId: $storeIds[0] ?? null);
        return Response::json($this->withStoreIds($saved));
    }

    /** DELETE /api/v1/shift-types/{id} */
    public function destroy(Request $request): Response
    {
        $old = $this->requireType($request, 'shifts.update');
        $id  = (int) $old['id'];
        $this->assertManagesEveryStore($request, $id);

        $this->shiftTypes->delete($id);
        $this->auditLogger->log($request, 'shift_type.deleted', 'shift_type', resourceId: $id);
        return Response::empty();
    }

    /**
     * Modifier ou supprimer un type partagé touche tous ses magasins : l'appelant doit détenir `shifts.update`
     * sur chacun d'eux (ou sans restriction de magasin).
     */
    private function assertManagesEveryStore(Request $request, int $typeId): void
    {
        foreach ($this->shiftTypes->getStoreIds($typeId) as $storeId) {
            if (!$this->permissions->can($this->authUser($request), 'shifts.update', (int) $storeId)) {
                throw new ForbiddenException(__('error_shared_shift_type'));
            }
        }
    }

    private function authUser(Request $request): array
    {
        return $request->getAttribute('auth_user') ?? [];
    }

    /** Charge le type par id et vérifie $permissionKey sur au moins un de ses magasins (ou sans restriction). */
    private function requireType(Request $request, string $permissionKey): array
    {
        $id   = (int) $request->param('id');
        $type = $this->shiftTypes->findById($id);
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }
        $this->permissions->requireOnAnyStore($this->authUser($request), $permissionKey, $this->shiftTypes->getStoreIds($id));
        return $type;
    }

    /** Chaque magasin cible doit être modifiable par l'appelant ; sans magasin, seule une portée illimitée est acceptée. */
    private function assertWritableStores(Request $request, array $storeIds): void
    {
        $authUser = $this->authUser($request);
        if ($storeIds === []) {
            $this->permissions->requireOnAnyStore($authUser, 'shifts.update', []);
            return;
        }
        foreach ($storeIds as $storeId) {
            $this->permissions->requireOnStore($authUser, 'shifts.update', $storeId);
        }
    }

    private function withStoreIds(array $type): array
    {
        $type['store_ids'] = $this->shiftTypes->getStoreIds((int) ($type['id'] ?? 0));
        return $type;
    }

    /** @return int[] */
    private function extractStoreIds(array $data): array
    {
        if (isset($data['store_ids']) && is_array($data['store_ids'])) {
            return array_values(array_unique(array_map('intval', $data['store_ids'])));
        }
        if (isset($data['store_id']) && $data['store_id'] !== '' && $data['store_id'] !== null) {
            return [(int) $data['store_id']];
        }
        return [];
    }
}
