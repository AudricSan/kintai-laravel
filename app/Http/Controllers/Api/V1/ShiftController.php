<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use kintai\Contract\Event\OpenShiftClosed;
use App\Api\Paginator;
use App\Auth\PermissionService;
use App\Exceptions\NotFoundException;
use App\Extension\Events;
use App\Models\Shift;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\SchedulePublication;
use App\Validation\ApiPayloadRules;
use App\Validation\PayloadValidator;

final class ShiftController
{
    public function __construct(
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
            $items = Shift::findByDate((int) $storeId, $date);
        } elseif ($storeId !== null) {
            $items = Shift::findByStore((int) $storeId);
        } elseif ($userId !== null) {
            $items = Shift::findByUser((int) $userId);
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
        // Brouillons (publication du planning) : réservés à qui gère le planning du magasin, comme dans les vues web.
        $publication = SchedulePublication::current();
        if ($publication !== null) {
            $items = array_values(array_filter(
                $items,
                fn(array $s): bool => $publication->isPublished($s)
                    || $this->permissions->can($authUser, 'shifts.update', (int) ($s['store_id'] ?? 0)),
            ));
        }

        return response()->json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/shifts/{id} */
    public function show(Request $request): Response
    {
        $shift = $this->requireShift($request, 'shifts.view');
        // Brouillon : introuvable pour qui ne gère pas le planning du magasin (même règle que la liste).
        if (!(SchedulePublication::current()?->isPublished($shift) ?? true)
            && !$this->permissions->can($this->authUser($request), 'shifts.update', (int) ($shift['store_id'] ?? 0))) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        return response()->json($shift);
    }

    /** POST /api/v1/shifts — `store_id` obligatoire (la permission est vérifiée sur ce magasin). */
    public function store(Request $request): Response
    {
        $data = PayloadValidator::validate($request->json()->all() ?? [], ApiPayloadRules::shift());
        $this->permissions->requireOnStore($this->authUser($request), 'shifts.create', $data['store_id']);
        // Champs calculés dérivés de la date et des heures, jamais pris du client (ils manquaient : erreur SQL).
        $data += ApiPayloadRules::shiftTiming($data['shift_date'], $data['start_time'], $data['end_time']);
        if (!empty($data['is_open'])) {
            $data['user_id'] = null;
        }

        $saved = Shift::put($data);
        $this->auditLogger->log($request, 'shift.created', 'shift', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: (int) $data['store_id']);
        return response()->json($saved, 201);
    }

    /** PUT /api/v1/shifts/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireShift($request, 'shifts.update');
        $id  = (int) $old['id'];

        $data = PayloadValidator::validateUpdate($request->json()->all() ?? [], ApiPayloadRules::shift());
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

        $saved = Shift::put(array_merge($data, ['id' => $id]));
        // Shift retiré de la bourse (contrat 1.17) : les bundles qui l'écoutent (shift-claim) l'apprennent.
        if (!empty($old['is_open']) && array_key_exists('is_open', $data) && empty($data['is_open'])) {
            $authId = (int) ($this->authUser($request)['id'] ?? 0);
            Events::current()->dispatch(new OpenShiftClosed($saved, $authId > 0 ? $authId : null));
        }
        $this->auditLogger->logUpdate($request, 'shift.updated', 'shift', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data, storeId: (int) ($saved['store_id'] ?? 0) ?: null);
        return response()->json($saved);
    }

    /** DELETE /api/v1/shifts/{id} */
    public function destroy(Request $request): Response
    {
        $old = $this->requireShift($request, 'shifts.delete');
        $id  = (int) $old['id'];

        Shift::remove($id);
        $this->auditLogger->log($request, 'shift.deleted', 'shift', resourceId: $id, storeId: (int) $old['store_id'] ?: null);
        return response()->noContent();
    }

    private function authUser(Request $request): array
    {
        return $request->attributes->get('auth_user') ?? [];
    }

    /** Charge le shift par id et vérifie $permissionKey sur son magasin réel. */
    private function requireShift(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => Shift::findById($id),
            (int) $request->route('id'),
            $permissionKey,
            notFoundMessage: __('error_shift_not_found'),
        );
    }
}
