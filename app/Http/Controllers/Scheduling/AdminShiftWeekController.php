<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use App\Auth\PermissionService;
use App\Exceptions\NotFoundException;
use App\Models\ShiftWeekTemplate;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;
use App\Services\SchedulePublication;
use App\Services\WeekPlanner;
use App\Http\Controllers\HasAdminAccess;

/**
 * Semaines types de la timeline de gestion : copier la semaine précédente, enregistrer la semaine affichée comme
 * modèle, appliquer ou supprimer un modèle (WeekPlanner). Chaque action revient sur la timeline avec un compte rendu.
 */
final class AdminShiftWeekController
{
    use HasAdminAccess;

    public function __construct(
        private readonly StoreRepositoryInterface $stores,
        private readonly UserRepositoryInterface $users,
        private readonly WeekPlanner $planner,
        private readonly NotificationService $notifs,
        private readonly PermissionService $permissions,
        private readonly AuditLogger $auditLogger,
        private readonly ?SchedulePublication $publication = null,
    ) {}

    /** POST /admin/shifts/copy-week — recrée sur la semaine `target_start` les shifts de la semaine précédente. */
    public function copyPreviousWeek(Request $request): Response
    {
        $storeId = $this->authorize($request, 'shifts.create');
        $target  = $this->postedDate($request, 'target_start');
        if ($target === null) {
            return $this->back($request, $storeId, ['error' => 'week_invalid']);
        }
        $source = (new \DateTimeImmutable($target))->modify('-7 days')->format('Y-m-d');

        $entries = $this->planner->entriesOfWeek($storeId, $source);
        if ($entries === []) {
            return $this->back($request, $storeId, ['error' => 'week_source_empty']);
        }
        $result = $this->planner->apply($storeId, $target, $entries, $this->authId($request));
        $this->afterApply($request, $storeId, $result, 'schedule.week_copied', ['source' => $source, 'target' => $target]);

        return $this->back($request, $storeId, $this->report('week_copied', $result));
    }

    /** POST /admin/shifts/templates — enregistre la semaine `week_start` comme modèle nommé (remplace le même nom). */
    public function saveTemplate(Request $request): Response
    {
        $storeId = $this->authorize($request, 'shifts.update');
        $week    = $this->postedDate($request, 'week_start');
        $name    = mb_substr(trim((string) $request->post('name', '')), 0, 100);
        if ($week === null || $name === '') {
            return $this->back($request, $storeId, ['error' => 'template_invalid']);
        }
        $entries = $this->planner->entriesOfWeek($storeId, $week);
        if ($entries === []) {
            return $this->back($request, $storeId, ['error' => 'week_source_empty']);
        }
        $saved = ShiftWeekTemplate::updateOrCreate(['store_id' => $storeId, 'name' => $name], ['entries' => array_values($entries), 'created_by' => $this->authId($request)]);
        $this->auditLogger->log($request, 'schedule.template_saved', 'shift_week_template', $saved->id, [
            'name' => $name, 'week' => $week, 'shifts' => count($entries),
        ], $storeId);

        return $this->back($request, $storeId, ['success' => 'template_saved']);
    }

    /** POST /admin/shifts/templates/{id}/apply — recrée les shifts du modèle sur la semaine `target_start`. */
    public function applyTemplate(Request $request): Response
    {
        $template = $this->template($request);
        $storeId  = $this->authorize($request, 'shifts.create', $template['store_id']);
        $target   = $this->postedDate($request, 'target_start');
        if ($target === null) {
            return $this->back($request, $storeId, ['error' => 'week_invalid']);
        }
        $result = $this->planner->apply($storeId, $target, $template['entries'], $this->authId($request));
        $this->afterApply($request, $storeId, $result, 'schedule.template_applied', ['template' => $template['name'], 'target' => $target]);

        return $this->back($request, $storeId, $this->report('template_applied', $result));
    }

    /** POST /admin/shifts/templates/{id}/delete */
    public function deleteTemplate(Request $request): Response
    {
        $template = $this->template($request);
        $storeId  = $this->authorize($request, 'shifts.update', $template['store_id']);
        $template->delete();
        $this->auditLogger->log($request, 'schedule.template_deleted', 'shift_week_template', $template['id'], [
            'name' => $template['name'],
        ], $storeId);

        return $this->back($request, $storeId, ['success' => 'template_deleted']);
    }

    // -------------------------------------------------------------------------

    /** Magasin de l'action (posté, ou celui du modèle) après contrôle de l'accès et de la permission. */
    private function authorize(Request $request, string $permission, ?int $storeId = null): int
    {
        $storeId ??= (int) $request->post('store_id', 0);
        $this->assertStoreAccess($request, $storeId);
        $this->permissions->requireOnStore($request->attributes->get('auth_user') ?? [], $permission, $storeId);
        return $storeId;
    }

    private function template(Request $request): ShiftWeekTemplate
    {
        return ShiftWeekTemplate::find((int) $request->route('id'))
            ?? throw new NotFoundException(__('error_resource_not_found'));
    }

    private function postedDate(Request $request, string $field): ?string
    {
        $value = (string) $request->post($field, '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }

    private function authId(Request $request): ?int
    {
        $id = (int) (($request->attributes->get('auth_user') ?? [])['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Journal d'audit, puis notification des employés pour les shifts créés sur des jours déjà publiés (un brouillon ne
     * prévient personne, voir SchedulePublication) : une seule par employé, qui compte ses nouveaux shifts.
     *
     * @param array{created: list<array<string, mixed>>, skipped: list<array<string, mixed>>} $result
     * @param array<string, mixed> $details
     */
    private function afterApply(Request $request, int $storeId, array $result, string $action, array $details): void
    {
        $this->auditLogger->log($request, $action, 'store', $storeId, $details + [
            'created' => count($result['created']),
            'skipped' => count($result['skipped']),
        ], $storeId);

        $byUser = [];
        foreach ($result['created'] as $shift) {
            $uid = (int) ($shift['user_id'] ?? 0);
            if ($uid > 0 && ($this->publication?->isPublished($shift) ?? true)) {
                $byUser[$uid][] = $shift;
            }
        }
        $store = $this->stores->findById($storeId);
        foreach ($byUser as $uid => $shifts) {
            usort($shifts, fn(array $a, array $b): int => strcmp((string) $a['shift_date'], (string) $b['shift_date']));
            try {
                $this->notifs->notify(
                    $uid,
                    'shift_assigned',
                    'notif_shifts_added_body',
                    ['count' => (string) count($shifts), 'from' => (string) $shifts[0]['shift_date'], 'store' => (string) ($store['name'] ?? '')],
                    (int) $shifts[0]['id'],
                    '/employee/shifts/day?start=' . $shifts[0]['shift_date'] . '&view=week',
                );
            } catch (\Throwable $e) {
                Log::warning('shift_notify_failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Paramètres du compte rendu affiché sur la timeline.
     *
     * @param array{created: list<array<string, mixed>>, skipped: list<array{reason: string}>} $result
     * @return array<string, string>
     */
    private function report(string $success, array $result): array
    {
        $reasons = [];
        foreach ($result['skipped'] as $skip) {
            $reasons[$skip['reason']] = ($reasons[$skip['reason']] ?? 0) + 1;
        }
        $params = ['success' => $success, 'created' => (string) count($result['created'])];
        foreach ($reasons as $reason => $count) {
            $params['skipped_' . $reason] = (string) $count;
        }
        return $params;
    }

    /** @param array<string, string> $params */
    private function back(Request $request, int $storeId, array $params): Response
    {
        $back = safe_redirect_path((string) $request->post('redirect_to', ''), '')
            ?: $this->base() . '/admin/shifts/timeline?store_id=' . $storeId;
        $sep  = str_contains($back, '?') ? '&' : '?';
        return redirect()->away($back . $sep . http_build_query($params));
    }
}
