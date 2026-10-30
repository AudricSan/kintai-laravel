<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use App\Auth\PermissionService;
use Illuminate\Container\Container;
use App\Exceptions\NotFoundException;
use App\Extension\ExtensionRegistry;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\TimesheetApproval;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\TimesheetService;
use App\Http\Controllers\HasAdminAccess;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Feuilles de temps : pour un magasin et une semaine, les shifts prévus comparés aux pointages (TimesheetService),
 * avec les écarts à traiter, l'export CSV, et la validation de la semaine par un gérant du planning.
 */
final class AdminTimesheetController
{
    use HasAdminAccess;

    public function __construct(
        private readonly ViewFactory $view,
        private readonly UserRepositoryInterface $users,
        private readonly TimesheetService $timesheets,
        private readonly PermissionService $permissions,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** GET /admin/timesheets?store_id=&week= */
    public function index(Request $request): Response
    {
        $stores = $this->availableStores($this->managedIds($request));
        $storeId = (int) ($request->query('store_id') ?? 0);
        $ids = array_map(static fn(array $s): int => (int) $s['id'], $stores);
        if (!in_array($storeId, $ids, true)) {
            $storeId = $ids[0] ?? 0;
        }

        $data = null;
        $approval = null;
        $store = null;
        $canApprove = false;
        if ($storeId > 0) {
            $this->assertStoreAccess($request, $storeId);
            $user = $request->attributes->get('auth_user') ?? [];
            $this->permissions->requireOnStore($user, 'shifts.view', $storeId);
            $store = Store::findById($storeId);
            $weekStart = $this->weekStart($store, (string) ($request->query('week') ?? ''));
            $data = $this->timesheets->week($storeId, $weekStart);
            $approval = TimesheetApproval::where('store_id', $storeId)->where('week_start', $weekStart)->first();
            $canApprove = $this->permissions->can($user, 'shifts.update', $storeId);
        }

        return response($this->view->make('scheduling.timesheets', [
            'title'          => __('timesheets'),
            'stores_map'     => array_column(array_map(static fn(array $s): array => ['id' => (int) $s['id'], 'name' => (string) ($s['name'] ?? '#' . $s['id'])], $stores), 'name', 'id'),
            'store_id'       => $storeId,
            'store'          => $store,
            'timesheet'      => $data,
            'approval'       => $approval,
            'approval_stale' => $approval !== null && $data !== null && $approval['fingerprint'] !== $data['fingerprint'],
            'can_approve'    => $canApprove,
            'users_map'      => $this->buildUsersMap(),
            'has_attendance' => $this->hasAttendanceProvider(),
        ])->render());
    }

    /** GET /admin/timesheets/export?store_id=&week= — la semaine en CSV (une ligne par shift ou pointage). */
    public function export(Request $request): Response
    {
        [$storeId, $store, $weekStart] = $this->target($request, 'shifts.view', fn(string $k) => $request->query($k));
        $data  = $this->timesheets->week($storeId, $weekStart);
        $names = $this->buildUsersMap();
        $hm    = static fn(?string $dt): string => $dt !== null && $dt !== '' ? substr($dt, 11, 5) : '';

        $buf = fopen('php://memory', 'r+');
        fprintf($buf, "\xEF\xBB\xBF");
        fputcsv($buf, [
            __('employee'), __('date'), __('timesheet_planned_start'), __('timesheet_planned_end'), __('timesheet_clock_in'),
            __('timesheet_clock_out'), __('timesheet_planned_minutes'), __('timesheet_worked_minutes'), __('timesheet_status'),
        ], ';');
        foreach ($data['rows'] as $r) {
            fputcsv($buf, [
                $names[$r['user_id']] ?? '#' . $r['user_id'],
                $r['date'],
                $r['shift'] !== null ? substr((string) $r['shift']['start_time'], 0, 5) : '',
                $r['shift'] !== null ? substr((string) $r['shift']['end_time'], 0, 5) : '',
                $hm($r['attendance']['clock_in_time'] ?? null),
                $hm($r['attendance']['clock_out_time'] ?? null),
                $r['planned_minutes'],
                $r['worked_minutes'],
                implode(', ', array_map(static fn(string $f): string => __('timesheet_flag_' . $f), $r['flags'])),
            ], ';');
        }
        rewind($buf);
        $csv = (string) stream_get_contents($buf);
        fclose($buf);

        $slug = preg_replace('/[^a-z0-9]+/i', '-', (string) ($store['code'] ?? $store['name'] ?? 'store'));
        return response()->csv($csv, 'feuille-de-temps-' . strtolower(trim((string) $slug, '-')) . '-' . $weekStart . '.csv');
    }

    /** POST /admin/timesheets/approve — valide la semaine telle qu'affichée. */
    public function approve(Request $request): Response
    {
        [$storeId, , $weekStart] = $this->target($request, 'shifts.update', fn(string $k) => $request->post($k));
        $data = $this->timesheets->week($storeId, $weekStart);
        $userId = (int) (($request->attributes->get('auth_user') ?? [])['id'] ?? 0);
        $totals = array_map(static fn(array $t): array => ['planned_minutes' => $t['planned_minutes'], 'worked_minutes' => $t['worked_minutes']], $data['totals']);
        TimesheetApproval::updateOrCreate(['store_id' => $storeId, 'week_start' => $weekStart], [
            'approved_by' => $userId > 0 ? $userId : null,
            'approved_at' => date('Y-m-d H:i:s'),
            'fingerprint' => $data['fingerprint'],
            'totals'      => $totals,
        ]);
        $this->auditLogger->log($request, 'timesheet.approved', 'store', $storeId, [
            'week'      => $weekStart,
            'anomalies' => array_sum(array_column($data['totals'], 'anomalies')),
            'planned'   => array_sum(array_column($totals, 'planned_minutes')),
            'worked'    => array_sum(array_column($totals, 'worked_minutes')),
        ], $storeId);

        return $this->back($storeId, $weekStart, 'approved');
    }

    /** POST /admin/timesheets/reopen — annule la validation de la semaine. */
    public function reopen(Request $request): Response
    {
        [$storeId, , $weekStart] = $this->target($request, 'shifts.update', fn(string $k) => $request->post($k));
        if (TimesheetApproval::where('store_id', $storeId)->where('week_start', $weekStart)->delete() > 0) {
            $this->auditLogger->log($request, 'timesheet.reopened', 'store', $storeId, ['week' => $weekStart], $storeId);
        }
        return $this->back($storeId, $weekStart, 'reopened');
    }

    // -------------------------------------------------------------------------

    /**
     * Magasin et semaine d'une action, après contrôle de l'accès et de la permission.
     *
     * @param \Closure(string): mixed $input lecture du paramètre (query ou post)
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function target(Request $request, string $permission, \Closure $input): array
    {
        $storeId = (int) ($input('store_id') ?? 0);
        $this->assertStoreAccess($request, $storeId);
        $this->permissions->requireOnStore($request->attributes->get('auth_user') ?? [], $permission, $storeId);
        $store = Store::findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        return [$storeId, $store, $this->weekStart($store, (string) ($input('week') ?? ''))];
    }

    /**
     * Premier jour de la semaine qui contient $date (aujourd'hui par défaut), selon le jour de début de semaine du
     * magasin (week_start_day, 1 = lundi … 7 = dimanche).
     *
     * @param ?array<string, mixed> $store
     */
    private function weekStart(?array $store, string $date): string
    {
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false
            ? new \DateTimeImmutable($date)
            : new \DateTimeImmutable('today');
        $startDay = max(1, min(7, (int) ($store['week_start_day'] ?? 1)));
        $offset = ((int) $anchor->format('N') - $startDay + 7) % 7;
        return $anchor->modify('-' . $offset . ' days')->format('Y-m-d');
    }

    /** Un bundle actif fournit-il des pointages (sans quoi chaque shift passé apparaît comme une absence) ? */
    private function hasAttendanceProvider(): bool
    {
        try {
            $container = Container::getInstance();
            if (!$container->has(ExtensionRegistry::class)) {
                return false;
            }
            return $container->make(ExtensionRegistry::class)->attendanceProvidersByBundle() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function back(int $storeId, string $weekStart, string $success): Response
    {
        return redirect()->away($this->base() . '/admin/timesheets?' . http_build_query([
            'store_id' => $storeId, 'week' => $weekStart, 'success' => $success,
        ]));
    }
}
