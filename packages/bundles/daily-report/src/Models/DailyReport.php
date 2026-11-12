<?php

namespace Kintai\Bundles\DailyReport\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Le rapport d'un magasin pour un jour (un seul par magasin et par jour) : brouillon, soumis, puis validé.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $author_id
 * @property Carbon $report_date
 * @property numeric-string $sales_total
 * @property int $customer_count
 * @property numeric-string $labor_cost
 * @property numeric-string $waste_total
 * @property string|null $notes
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property Carbon|null $mail_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $author
 * @property-read User|null $validator
 */
#[Fillable(['store_id', 'author_id', 'report_date', 'sales_total', 'customer_count', 'labor_cost', 'waste_total', 'notes', 'status', 'submitted_at', 'validated_by', 'validated_at', 'mail_sent_at'])]
class DailyReport extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const VALIDATED = 'validated';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::VALIDATED];

    protected $table = 'daily_reports';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'sales_total' => 'decimal:2',
            'customer_count' => 'integer',
            'labor_cost' => 'decimal:2',
            'waste_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'mail_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::SUBMITTED => 'Submitted',
            self::VALIDATED => 'Validated',
            default => 'Draft',
        };
    }
}
