<?php

namespace Kintai\Bundles\ResignationReport\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kintai\Bundles\ResignationReport\Database\Factories\ResignationReportFactory;

/**
 * Le rapport de démission d'un employé dans un magasin : date, motif, préavis, remarques et responsable. Tout son
 * contenu est personnel : il ne se lit qu'à travers `ResignationReportAccess` et ne figure jamais dans le journal
 * d'activité.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property string|null $employee_number
 * @property string|null $employee_name
 * @property Carbon|null $resignation_date
 * @property string|null $reason
 * @property string|null $resignation_notice
 * @property string|null $notes
 * @property string|null $person_in_charge
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $employee
 * @property-read User|null $creator
 */
#[Fillable([
    'store_id', 'user_id', 'employee_number', 'employee_name', 'resignation_date', 'reason', 'resignation_notice',
    'notes', 'person_in_charge', 'created_by',
])]
class ResignationReport extends Model
{
    /** @use HasFactory<ResignationReportFactory> */
    use HasFactory;

    /** Les champs du contenu, ceux du formulaire (le magasin, l'employé et l'auteur se règlent à part). */
    public const FIELDS = [
        'employee_number', 'employee_name', 'resignation_date', 'reason', 'resignation_notice', 'notes', 'person_in_charge',
    ];

    protected static function newFactory(): ResignationReportFactory
    {
        return ResignationReportFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resignation_date' => 'date:Y-m-d'];
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
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
