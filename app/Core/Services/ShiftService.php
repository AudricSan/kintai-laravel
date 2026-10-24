<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Extension\Absences;
use kintai\Core\Repositories\UserRepositoryInterface;

final class ShiftService implements ShiftServiceInterface
{
    public function __construct(
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function getShiftsForAdmin(array $storeIds, string $month, int $filterStoreId = 0, int $filterUserId = 0): array
    {
        $monthStart = $month . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        // Plage et magasins filtrés en base : l'historique complet n'est plus chargé.
        $all = $this->shifts->findBetween($storeIds, $monthStart, $monthEnd);

        return array_values(array_filter($all, function ($s) use ($storeIds, $monthStart, $monthEnd, $filterStoreId, $filterUserId) {
            $sid = (int) ($s['store_id'] ?? 0);
            $uid = (int) ($s['user_id'] ?? 0);
            $date = $s['shift_date'] ?? '';

            if (!in_array($sid, $storeIds, true)) return false;
            if ($date < $monthStart || $date > $monthEnd) return false;
            if ($filterStoreId > 0 && $sid !== $filterStoreId) return false;
            if ($filterUserId > 0 && $uid !== $filterUserId) return false;

            return true;
        }));
    }

    public function getUsersMap(): array
    {
        $map = [];
        foreach ($this->users->findAll() as $u) {
            $map[(int) $u['id']] = $u['display_name'];
        }
        return $map;
    }

    public function getShiftTypesMap(): array
    {
        $map = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $map[(int) $t['id']] = $t;
        }
        return $map;
    }

    public function getCalendarData(array $userIds, string $startDate, string $endDate, int $filterStoreId = 0): array
    {
        $shiftsByDate = [];
        foreach ($userIds as $uid) {
            foreach ($this->shifts->findByUser($uid) as $s) {
                $d = $s['shift_date'] ?? '';
                if ($d < $startDate || $d > $endDate) continue;
                if ($filterStoreId > 0 && (int) ($s['store_id'] ?? 0) !== $filterStoreId) continue;
                $shiftsByDate[$d][] = $s;
            }
        }
        return $shiftsByDate;
    }

    public function getTimeoffForCalendar(array $userIds, string $startDate, string $endDate): array
    {
        $timeoffByDate = [];
        // Absences lues à travers les fournisseurs (Bundle::absenceProviders()) ; aucune sans bundle timeoff.
        $absences = Absences::current();
        foreach ($userIds as $uid) {
            foreach ($absences->forUser((int) $uid) as $req) {
                if (($req['status'] ?? '') !== 'approved') continue;
                $start = max($req['start_date'] ?? '', $startDate);
                $end   = min($req['end_date'] ?? '', $endDate);
                if ($start > $end) continue;
                $cursor = new \DateTimeImmutable($start);
                $last   = new \DateTimeImmutable($end);
                while ($cursor <= $last) {
                    $timeoffByDate[$cursor->format('Y-m-d')][] = [
                        'user_id' => (int) $uid,
                        'type'    => $req['type'] ?? 'vacation',
                    ];
                    $cursor = $cursor->modify('+1 day');
                }
            }
        }
        return $timeoffByDate;
    }
}
