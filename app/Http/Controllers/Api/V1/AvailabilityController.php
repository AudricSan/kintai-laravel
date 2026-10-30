<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Paginator;
use App\Auth\PermissionService;
use App\Models\Availability;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Validation\ApiPayloadRules;
use App\Validation\PayloadValidator;

final class AvailabilityController
{
    public function __construct(
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
            $items = Availability::findByUser((int) $userId);
        } elseif ($storeId !== null) {
            $items = Availability::findByStore((int) $storeId);
        } else {
            $items = [];
        }

        $items = $this->permissions->restrictToScope($this->authUser($request), 'shifts.view', $items);

        return response()->json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/availabilities/{id} */
    public function show(Request $request): Response
    {
        return response()->json($this->requireAvailability($request, 'shifts.view'));
    }

    /** POST /api/v1/availabilities — `store_id` obligatoire (la permission est vérifiée sur ce magasin). */
    public function store(Request $request): Response
    {
        $data = PayloadValidator::validate($request->json()->all() ?? [], ApiPayloadRules::availability());
        $this->permissions->requireOnStore($this->authUser($request), 'shifts.update', $data['store_id']);

        $saved = Availability::put($data);
        $this->auditLogger->log($request, 'availability.created', 'availability', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: (int) $data['store_id']);
        return response()->json($saved, 201);
    }

    /** PUT /api/v1/availabilities/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireAvailability($request, 'shifts.update');
        $id  = (int) $old['id'];

        $data = PayloadValidator::validateUpdate($request->json()->all() ?? [], ApiPayloadRules::availability());
        // Déplacer une disponibilité vers un autre magasin exige aussi le droit sur la destination.
        if (isset($data['store_id']) && (int) $data['store_id'] !== (int) $old['store_id']) {
            $this->permissions->requireOnStore($this->authUser($request), 'shifts.update', $data['store_id']);
        }

        $saved = Availability::put(array_merge($data, ['id' => $id]));
        $this->auditLogger->logUpdate($request, 'availability.updated', 'availability', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data, storeId: (int) ($saved['store_id'] ?? 0) ?: null);
        return response()->json($saved);
    }

    /** DELETE /api/v1/availabilities/{id} */
    public function destroy(Request $request): Response
    {
        $old = $this->requireAvailability($request, 'shifts.update');
        $id  = (int) $old['id'];

        Availability::remove($id);
        $this->auditLogger->log($request, 'availability.deleted', 'availability', resourceId: $id, storeId: (int) $old['store_id'] ?: null);
        return response()->noContent();
    }

    private function authUser(Request $request): array
    {
        return $request->attributes->get('auth_user') ?? [];
    }

    /** Charge la disponibilité par id et vérifie $permissionKey sur son magasin réel. */
    private function requireAvailability(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => Availability::findById($id),
            (int) $request->route('id'),
            $permissionKey,
            notFoundMessage: __('error_availability_not_found'),
        );
    }
}
