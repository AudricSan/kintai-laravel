<?php

namespace Kintai\Bundles\SalaryReport\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Retenue personnalisée d'un magasin, définie par son responsable : un pourcentage du brut de l'employé (`percent`) ou un
 * montant mensuel fixe par employé payé (`fixed`). Le nom est libre (non traduit).
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string $kind
 * @property numeric-string $value
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 */
#[Fillable(['store_id', 'name', 'kind', 'value', 'sort_order', 'is_active'])]
class SalaryReportDeduction extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const KINDS = [self::PERCENT, self::FIXED];

    /** Nombre maximal de retenues personnalisées par magasin. */
    public const MAX_PER_STORE = 20;

    protected $table = 'salary_report_deductions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
