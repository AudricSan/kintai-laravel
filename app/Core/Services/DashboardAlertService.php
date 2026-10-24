<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Extension\Attendances;
use kintai\Core\Extension\PendingRequests;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Regroupe les "alertes actionnables" du dashboard admin : shifts à venir non
 * pourvus, employés actifs sans planning cette semaine, demandes en attente
 * depuis trop longtemps, pointages ouverts depuis trop longtemps. Extrait de
 * HomeController pour ne pas alourdir davantage son index() déjà chargé.
 */
final class DashboardAlertService
{
    private const UPCOMING_WINDOW_DAYS  = 7;
    private const STALE_REQUEST_DAYS    = 3;
    private const STALE_TIMECLOCK_HOURS = 12;

    public function __construct(
        private readonly ShiftRepositoryInterface $shifts,
        private readonly UserRepositoryInterface $users,
        private readonly StoreUserRepositoryInterface $storeUsers,
        // Demandes en attente (fournisseurs des bundles) ; sans elles (tests), celles de la requête courante.
        private readonly ?PendingRequests $pendingRequests = null,
    ) {}

    /**
     * @param int[]  $storeIds  stores en scope (déjà filtrés par managed_store_ids)
     * @param array  $storesMap store_id => nom, pour l'affichage
     */
    public function build(array $storeIds, array $storesMap): array
    {
        $storeIds = array_map('intval', $storeIds);

        return [
            'unfilled_shifts'     => $this->unfilledUpcomingShifts($storeIds, $storesMap),
            'users_without_shift' => $this->usersWithoutShiftThisWeek($storeIds, $storesMap),
            'stale_requests'      => $this->staleRequests($storeIds, $storesMap),
            'stale_timeclocks'    => $this->staleTimeclocks($storeIds, $storesMap),
        ];
    }

    private function unfilledUpcomingShifts(array $storeIds, array $storesMap): array
    {
        $result = [];
        for ($d = 0; $d <= self::UPCOMING_WINDOW_DAYS; $d++) {
            $date = date('Y-m-d', strtotime("+{$d} days"));
            foreach ($this->shifts->findAllByDate($date) as $s) {
                $sid = (int) ($s['store_id'] ?? 0);
                if (!in_array($sid, $storeIds, true) || $s['user_id'] !== null || !empty($s['deleted_at'])) {
                    continue;
                }
                $result[] = [
                    'id'         => (int) $s['id'],
                    'store_id'   => $sid,
                    'store_name' => $storesMap[$sid] ?? ('#' . $sid),
                    'shift_date' => (string) $s['shift_date'],
                    'start_time' => (string) $s['start_time'],
                    'end_time'   => (string) $s['end_time'],
                ];
            }
        }
        return $result;
    }

    private function usersWithoutShiftThisWeek(array $storeIds, array $storesMap): array
    {
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $scheduledUserIds = [];
        for ($d = 0; $d < 7; $d++) {
            $date = date('Y-m-d', strtotime("{$weekStart} +{$d} days"));
            foreach ($this->shifts->findAllByDate($date) as $s) {
                if ($s['user_id'] !== null) {
                    $scheduledUserIds[(int) $s['user_id']] = true;
                }
            }
        }

        $result = [];
        foreach ($storeIds as $sid) {
            foreach ($this->storeUsers->findByStore($sid) as $su) {
                $uid = (int) $su['user_id'];
                if (isset($scheduledUserIds[$uid]) || isset($result[$uid])) {
                    continue;
                }
                $user = $this->users->findById($uid);
                if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
                    continue;
                }
                $result[$uid] = [
                    'id'         => $uid,
                    'name'       => $user['display_name'] ?? $user['email'] ?? ('#' . $uid),
                    'store_id'   => $sid,
                    'store_name' => $storesMap[$sid] ?? ('#' . $sid),
                ];
            }
        }
        return array_values($result);
    }

    /**
     * Demandes en attente depuis plus de STALE_REQUEST_DAYS jours, lues auprès des fournisseurs de demandes des bundles
     * actifs (congés, échanges, candidatures à la bourse…, contrat 1.9), les plus anciennes en premier.
     */
    private function staleRequests(array $storeIds, array $storesMap = []): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-' . self::STALE_REQUEST_DAYS . ' days'));

        return array_map(static fn(array $r): array => $r + ['store_name' => $storesMap[$r['store_id']] ?? ('#' . $r['store_id'])],
            ($this->pendingRequests ?? PendingRequests::current())->olderThan($storeIds, $threshold));
    }

    private function staleTimeclocks(array $storeIds, array $storesMap): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-' . self::STALE_TIMECLOCK_HOURS . ' hours'));
        $result = [];

        // Pointages lus à travers les fournisseurs (Bundle::attendanceProviders()) ; aucun sans bundle timeclock.
        foreach (Attendances::current()->all() as $tc) {
            $sid = (int) ($tc['store_id'] ?? 0);
            if (!in_array($sid, $storeIds, true) || !empty($tc['clock_out_time'])) {
                continue;
            }
            if (($tc['clock_in_time'] ?? '') > $threshold) {
                continue;
            }
            $user = $this->users->findById((int) ($tc['user_id'] ?? 0));
            $result[] = [
                'id'            => (int) $tc['id'],
                'store_id'      => $sid,
                'store_name'    => $storesMap[$sid] ?? ('#' . $sid),
                'user_name'     => $user['display_name'] ?? ('#' . (int) ($tc['user_id'] ?? 0)),
                'clock_in_time' => (string) $tc['clock_in_time'],
            ];
        }

        return $result;
    }
}
