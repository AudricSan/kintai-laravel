<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\Timeclock\Controllers\Api;

use kintai\Bundles\Installed\Timeclock\Models\Timeclock;
use kintai\Contract\Http\Paginator;
use kintai\Contract\Auth\PermissionService;
use kintai\Contract\Exceptions\ConflictException;
use kintai\Contract\Exceptions\NotFoundException;
use kintai\Contract\Data\StoreUser;
use kintai\Contract\Http\Request;
use kintai\Contract\Http\Response;
use kintai\Contract\Services\AuditLogger;

/**
 * Régression (audit RBAC du 11/09/2026) : mêmes correctifs que
 * ShiftSwapRequestController — voir son docblock pour le détail de la faille.
 */
final class TimeclockController
{
    /** Champs qu'un gestionnaire peut corriger sur un pointage (la durée est recalculée, l'adresse IP et le rattachement ne changent pas). */
    private const UPDATE_FIELDS = ['shift_date', 'clock_in_time', 'clock_out_time', 'notes'];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/timeclocks?store_id=X&user_id=Y&date=Z&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId = $request->query('store_id');
        $userId  = $request->query('user_id');
        $date    = $request->query('date');

        if ($storeId !== null && $date !== null) {
            $items = Timeclock::findByStoreAndDate((int) $storeId, $date);
        } elseif ($userId !== null && $date !== null) {
            $items = Timeclock::findByUserAndDate((int) $userId, $date);
        } elseif ($storeId !== null) {
            $items = Timeclock::findByStore((int) $storeId);
        } elseif ($userId !== null) {
            $items = Timeclock::findByUser((int) $userId);
        } else {
            $items = [];
        }

        // Éléments couverts par la permission, PLUS ceux de l'appelant : un employé sans timeclock.view lit les siens avec
        // ?user_id=<lui> (règle self de la route). Avant, restrictToScope() vidait toujours sa liste.
        $authUser  = $this->authUser($request);
        $authId    = (int) ($authUser['id'] ?? 0);
        $scopedIds = array_column($this->permissions->restrictToScope($authUser, 'timeclock.view', $items), 'id');
        $items     = array_values(array_filter(
            $items,
            fn(array $i): bool => in_array($i['id'] ?? null, $scopedIds, true) || (int) ($i['user_id'] ?? 0) === $authId,
        ));

        return response()->json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/timeclocks/{id} */
    public function show(Request $request): Response
    {
        $record = $this->requireTimeclock($request, 'timeclock.view');
        return response()->json($record);
    }

    /** POST /api/v1/timeclocks/clock-in */
    public function clockIn(Request $request): Response
    {
        $data   = $request->json()->all() ?? [];
        $userId = (int) ($data['user_id'] ?? 0);

        if (Timeclock::findActiveByUser($userId) !== null) {
            throw new ConflictException(__('timeclock_error_clockin_already_active'));
        }

        $storeId = (int) ($data['store_id'] ?? 0);
        if ($storeId === 0) {
            $memberships = StoreUser::findByUser($userId);
            $storeId     = $memberships ? (int) $memberships[0]['store_id'] : 0;
        }
        // Audit du 03/10/2026 : le store_id du corps n'était jamais comparé aux magasins de l'employé.
        if ($storeId > 0 && StoreUser::findMembership($storeId, $userId) === null) {
            throw new \kintai\Contract\Exceptions\ForbiddenException(__('error_access_denied'));
        }

        $now    = date('Y-m-d H:i:s');
        $record = Timeclock::put([
            'user_id'        => $userId,
            'store_id'       => $storeId,
            'shift_date'     => date('Y-m-d'),
            'clock_in_time'  => $now,
            'clock_out_time' => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $this->auditLogger->log($request, 'timeclock.clock_in', 'timeclock', resourceId: (int) ($record['id'] ?? 0) ?: null, details: ['user_id' => $userId], storeId: $storeId);
        return response()->json($record, 201);
    }

    /** POST /api/v1/timeclocks/clock-out */
    public function clockOut(Request $request): Response
    {
        $data   = $request->json()->all() ?? [];
        $userId = (int) ($data['user_id'] ?? 0);

        $active = Timeclock::findActiveByUser($userId);
        if ($active === null) {
            throw new NotFoundException(__('timeclock_error_no_active_clockin'));
        }

        $now          = date('Y-m-d H:i:s');
        $clockInTime  = new \DateTimeImmutable($active['clock_in_time']);
        $clockOutTime = new \DateTimeImmutable($now);
        $duration     = (int) round(($clockOutTime->getTimestamp() - $clockInTime->getTimestamp()) / 60);

        $record = Timeclock::put(array_merge($active, [
            'clock_out_time'   => $now,
            'duration_minutes' => $duration,
            'updated_at'       => $now,
        ]));
        $this->auditLogger->logUpdate($request, 'timeclock.clock_out', 'timeclock', resourceId: (int) ($record['id'] ?? 0) ?: null, oldData: $active, newData: $record, extraContext: ['user_id' => $userId], storeId: isset($active['store_id']) ? (int) $active['store_id'] : null);
        return response()->json($record);
    }

    /** PUT /api/v1/timeclocks/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireTimeclock($request, 'timeclock.update');
        $id  = (int) $old['id'];

        // Audit du 03/10/2026 : l'employé et le magasin du pointage ne changent pas (déplacer l'entrée vers un magasin tiers contournait requireTimeclock()).
        $body = $request->json()->all() ?? [];
        unset($body['user_id'], $body['store_id'], $body['created_at']);
        // Liste blanche (audit SEC-14) : seuls ces champs se modifient, jamais une colonne choisie par le client.
        $body = array_intersect_key($body, array_flip(self::UPDATE_FIELDS));
        $data   = array_merge($body, ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')]);
        $record = Timeclock::put($data);

        if (!empty($data['clock_in_time']) && !empty($data['clock_out_time'])) {
            $in       = new \DateTimeImmutable($record['clock_in_time']);
            $out      = new \DateTimeImmutable($record['clock_out_time']);
            $duration = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
            $record   = Timeclock::put(array_merge($record, ['duration_minutes' => $duration]));
        }

        $this->auditLogger->logUpdate($request, 'timeclock.updated', 'timeclock', resourceId: $id, oldData: $old, newData: $record, extraContext: $body, storeId: (int) ($old['store_id'] ?? 0) ?: null);
        return response()->json($record);
    }

    /** DELETE /api/v1/timeclocks/{id} */
    public function destroy(Request $request): Response
    {
        $record = $this->requireTimeclock($request, 'timeclock.delete');
        $id     = (int) $record['id'];
        Timeclock::remove($id);
        $this->auditLogger->log($request, 'timeclock.deleted', 'timeclock', resourceId: $id);
        return response()->noContent();
    }

    private function authUser(Request $request): array
    {
        return $request->attributes->get('auth_user') ?? [];
    }

    /** Charge l'entrée par id et vérifie $permissionKey sur son store réel. */
    private function requireTimeclock(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => Timeclock::findById($id),
            (int) $request->route('id'),
            $permissionKey,
            notFoundMessage: __('timeclock_error_entry_not_found'),
        );
    }
}
