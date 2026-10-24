<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Contract\Event\OpenShiftClosed;
use kintai\Core\Api\Paginator;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Extension\Events;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;

final class ShiftController
{
    public function __construct(
        private readonly ShiftRepositoryInterface $shifts,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/shifts?store_id=X&user_id=Y&date=Z&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId = $request->query('store_id');
        $userId  = $request->query('user_id');
        $date    = $request->query('date');

        if ($storeId !== null && $date !== null) {
            $items = $this->shifts->findByDate((int) $storeId, $date);
        } elseif ($storeId !== null) {
            $items = $this->shifts->findByStore((int) $storeId);
        } elseif ($userId !== null) {
            $items = $this->shifts->findByUser((int) $userId);
        } else {
            $items = [];
        }

        // Shifts visibles par la permission, plus ses propres shifts : un employé sans shifts.view consulte son
        // planning avec ?user_id=<lui> (route en libre-service, règle self). Avant, la liste lui était refusée.
        $authUser = $this->authUser($request);
        $authId   = (int) ($authUser['id'] ?? 0);
        $scopedIds = array_column($this->permissions->restrictToScope($authUser, 'shifts.view', $items), 'id');
        $items = array_values(array_filter(
            $items,
            fn(array $s): bool => in_array($s['id'] ?? null, $scopedIds, true) || (int) ($s['user_id'] ?? 0) === $authId,
        ));

        return Response::json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/shifts/{id} */
    public function show(Request $request): Response
    {
        return Response::json($this->requireShift($request, 'shifts.view'));
    }

    /** POST /api/v1/shifts — `store_id` obligatoire (la permission est vérifiée sur ce magasin). */
    public function store(Request $request): Response
    {
        $data = PayloadValidator::validate($request->json() ?? [], ApiPayloadRules::shift());
        $this->permissions->requireOnStore($this->authUser($request), 'shifts.create', $data['store_id']);
        // Champs calculés dérivés de la date et des heures, jamais pris du client (ils manquaient : erreur SQL).
        $data += ApiPayloadRules::shiftTiming($data['shift_date'], $data['start_time'], $data['end_time']);
        if (!empty($data['is_open'])) {
            $data['user_id'] = null;
        }

        $saved = $this->shifts->save($data);
        $this->auditLogger->log($request, 'shift.created', 'shift', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: (int) $data['store_id']);
        return Response::json($saved, 201);
    }

    /** PUT /api/v1/shifts/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireShift($request, 'shifts.update');
        $id  = (int) $old['id'];

        $data = PayloadValidator::validateUpdate($request->json() ?? [], ApiPayloadRules::shift());
        // Date ou heures modifiées : les champs calculés suivent.
        if (array_intersect_key($data, array_flip(['shift_date', 'start_time', 'end_time'])) !== []) {
            $data += ApiPayloadRules::shiftTiming(
                $data['shift_date'] ?? (string) $old['shift_date'],
                $data['start_time'] ?? substr((string) $old['start_time'], 0, 5),
                $data['end_time'] ?? substr((string) $old['end_time'], 0, 5),
            );
        }
        // Déplacer un shift vers un autre magasin exige aussi le droit sur la destination.
        if (isset($data['store_id']) && (int) $data['store_id'] !== (int) $old['store_id']) {
            $this->permissions->requireOnStore($this->authUser($request), 'shifts.update', $data['store_id']);
        }

        $saved = $this->shifts->save(array_merge($data, ['id' => $id]));
        // Shift retiré de la bourse (contrat 1.17) : les bundles qui l'écoutent (shift-claim) l'apprennent.
        if (!empty($old['is_open']) && array_key_exists('is_open', $data) && empty($data['is_open'])) {
            $authId = (int) ($this->authUser($request)['id'] ?? 0);
            Events::current()->dispatch(new OpenShiftClosed($saved, $authId > 0 ? $authId : null));
        }
        $this->auditLogger->logUpdate($request, 'shift.updated', 'shift', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data, storeId: (int) ($saved['store_id'] ?? 0) ?: null);
        return Response::json($saved);
    }

    /** DELETE /api/v1/shifts/{id} */
    public function destroy(Request $request): Response
    {
        $old = $this->requireShift($request, 'shifts.delete');
        $id  = (int) $old['id'];

        $this->shifts->delete($id);
        $this->auditLogger->log($request, 'shift.deleted', 'shift', resourceId: $id, storeId: (int) $old['store_id'] ?: null);
        return Response::empty();
    }

    private function authUser(Request $request): array
    {
        return $request->getAttribute('auth_user') ?? [];
    }

    /** Charge le shift par id et vérifie $permissionKey sur son magasin réel. */
    private function requireShift(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => $this->shifts->findById($id),
            (int) $request->param('id'),
            $permissionKey,
            notFoundMessage: __('error_shift_not_found'),
        );
    }
}
