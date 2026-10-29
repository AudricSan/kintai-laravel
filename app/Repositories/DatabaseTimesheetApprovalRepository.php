<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TimesheetApproval;

final class DatabaseTimesheetApprovalRepository implements TimesheetApprovalRepositoryInterface
{
    public function find(int $storeId, string $weekStart): ?array
    {
        $row = TimesheetApproval::where('store_id', $storeId)->where('week_start', $weekStart)->first();
        return $row ? self::toArray($row) : null;
    }

    public function approve(int $storeId, string $weekStart, ?int $approvedBy, string $fingerprint, array $totals): array
    {
        $row = TimesheetApproval::where('store_id', $storeId)->where('week_start', $weekStart)->first() ?? new TimesheetApproval();
        $row->fill([
            'store_id'    => $storeId,
            'week_start'  => $weekStart,
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'fingerprint' => $fingerprint,
            'totals'      => json_encode($totals, JSON_THROW_ON_ERROR),
        ]);
        $row->save();
        return self::toArray($row);
    }

    public function reopen(int $storeId, string $weekStart): int
    {
        return TimesheetApproval::where('store_id', $storeId)->where('week_start', $weekStart)->delete();
    }

    /** @return array{id: int, store_id: int, week_start: string, approved_by: ?int, approved_at: string, fingerprint: string, totals: array<int|string, mixed>} */
    private static function toArray(TimesheetApproval $row): array
    {
        $totals = json_decode((string) $row->totals, true);
        return [
            'id'          => (int) $row->id,
            'store_id'    => (int) $row->store_id,
            'week_start'  => substr((string) $row->week_start, 0, 10),
            'approved_by' => $row->approved_by !== null ? (int) $row->approved_by : null,
            'approved_at' => (string) $row->approved_at,
            'fingerprint' => (string) $row->fingerprint,
            'totals'      => is_array($totals) ? $totals : [],
        ];
    }
}
