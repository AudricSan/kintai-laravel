<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\PlanningCheck\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historique des envois d'un planning validé (table planning_check_sends).
 */
final class PlanningCheckSend extends Model
{
    protected $table = 'planning_check_sends';
    public $timestamps = false;
    protected $guarded = [];

    /** @param array<string, mixed> $send check_id, sent_by, status, shifts_hash, recipients, error */
    public static function add(array $send): void
    {
        self::query()->create([
            'check_id'    => (int) $send['check_id'],
            'sent_by'     => $send['sent_by'] ?? null,
            'status'      => (string) $send['status'],
            'shifts_hash' => (string) ($send['shifts_hash'] ?? ''),
            'recipients'  => json_encode(array_values((array) ($send['recipients'] ?? [])), JSON_UNESCAPED_UNICODE),
            'error'       => $send['error'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string, mixed>> les plus récents d'abord */
    public static function forCheck(int $checkId): array
    {
        return self::query()->where('check_id', $checkId)->orderByDesc('id')->get()->map(fn(self $s): array => $s->toArray())->all();
    }
}
