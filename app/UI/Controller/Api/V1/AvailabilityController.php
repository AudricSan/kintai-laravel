<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Api\Paginator;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;

final class AvailabilityController
{
    public function __construct(
        private readonly AvailabilityRepositoryInterface $availabilities,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/availabilities?store_id=X&user_id=Y&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId = $request->query('store_id');
        $userId  = $request->query('user_id');

        if ($userId !== null) {
            $items = $this->availabilities->findByUser((int) $userId);
        } elseif ($storeId !== null) {
            $items = $this->availabilities->findByStore((int) $storeId);
        } else {
            $items = [];
        }

        $items = $this->permissions->restrictToScope($this->authUser($request), 'shifts.view', $items);

        return Response::json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/availabilities/{id} */
    public function show(Request $request): Response
    {
        return Response::json($this->requireAvailability($request, 'shifts.view'));
    }

    /** POST /api/v1/availabilities — `store_id` obligatoire (la permission est vérifiée sur ce magasin). */
    public function store(Request $request): Response
    {
        $data = PayloadValidator::validate($request->json() ?? [], ApiPayloadRules::availability());
        $this->permissions->requireOnStore($this->authUser($request), 'shifts.update', $data['store_id']);

        $saved = $this->availabilities->save($data);
        $this->auditLogger->log($request, 'availability.created', 'availability', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: (int) $data['store_id']);
        return Response::json($saved, 201);
    }

    /** PUT /api/v1/availabilities/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireAvailability($request, 'shifts.update');
        $id  = (int) $old['id'];

        $data = PayloadValidator::validateUpdate($request->json() ?? [], ApiPayloadRules::availability());
        // Déplacer une disponibilité vers un autre magasin exige aussi le droit sur la destination.
        if (isset($data['store_id']) && (int) $data['store_id'] !== (int) $old['store_id']) {
            $this->permissions->requireOnStore($this->authUser($request), 'shifts.update', $data['store_id']);
        }

        $saved = $this->availabilities->save(array_merge($data, ['id' => $id]));
        $this->auditLogger->logUpdate($request, 'availability.updated', 'availability', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data, storeId: (int) ($saved['store_id'] ?? 0) ?: null);
        return Response::json($saved);
    }

    /** DELETE /api/v1/availabilities/{id} */
    public function destroy(Request $request): Response
    {
        $old = $this->requireAvailability($request, 'shifts.update');
        $id  = (int) $old['id'];

        $this->availabilities->delete($id);
        $this->auditLogger->log($request, 'availability.deleted', 'availability', resourceId: $id, storeId: (int) $old['store_id'] ?: null);
        return Response::empty();
    }

    private function authUser(Request $request): array
    {
        return $request->getAttribute('auth_user') ?? [];
    }

    /** Charge la disponibilité par id et vérifie $permissionKey sur son magasin réel. */
    private function requireAvailability(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => $this->availabilities->findById($id),
            (int) $request->param('id'),
            $permissionKey,
            notFoundMessage: __('error_availability_not_found'),
        );
    }
}
