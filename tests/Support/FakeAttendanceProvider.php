<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Contract\Provider\Attendance;
use kintai\Contract\Provider\AttendanceProvider;

/**
 * Fournisseur de pointages factice, à la place de celui que déclare timeclock : pour tester les pages et services du
 * Core qui lisent les pointages (tableau de bord, alertes, widget RH). Les lignes ont la forme de la table timeclocks.
 */
final class FakeAttendanceProvider implements AttendanceProvider
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    public function attendance(?array $userIds, ?array $storeIds, ?string $from = null, ?string $to = null): array
    {
        $attendance = [];
        foreach ($this->rows as $r) {
            $day = (string) ($r['shift_date'] ?? substr((string) ($r['clock_in_time'] ?? ''), 0, 10));
            if (($userIds !== null && !in_array((int) $r['user_id'], $userIds, true))
                || ($storeIds !== null && !in_array((int) $r['store_id'], $storeIds, true))
                || ($from !== null && $day < $from) || ($to !== null && $day > $to)) {
                continue;
            }
            $attendance[] = new Attendance((int) $r['id'], (int) $r['user_id'], (int) $r['store_id'], $day,
                (string) $r['clock_in_time'], empty($r['clock_out_time']) ? null : (string) $r['clock_out_time']);
        }
        return $attendance;
    }
}
