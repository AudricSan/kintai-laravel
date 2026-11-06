<?php

namespace Kintai\Bundles\HiringReport\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kintai\Bundles\HiringReport\Database\Factories\HiringReportFactory;

/**
 * Le rapport d'embauche d'un employé dans un magasin : identité, coordonnées, garant et emploi. Tout son contenu est
 * personnel : il ne se lit qu'à travers `HiringReportAccess` et ne figure jamais dans le journal d'activité.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property string|null $employee_number
 * @property string|null $employee_name
 * @property string|null $furigana_last_name
 * @property string|null $furigana_first_name
 * @property string|null $gender
 * @property string|null $tax_classification
 * @property Carbon|null $birth_date
 * @property Carbon|null $hire_date
 * @property string|null $education
 * @property string|null $postal_code
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $mobile_phone
 * @property string|null $email
 * @property string|null $guarantor_name
 * @property string|null $guarantor_phone
 * @property string|null $store_name
 * @property string|null $hired_by
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $employee
 * @property-read User|null $creator
 */
#[Fillable([
    'store_id', 'user_id', 'employee_number', 'employee_name', 'furigana_last_name', 'furigana_first_name', 'gender',
    'tax_classification', 'birth_date', 'hire_date', 'education', 'postal_code', 'address', 'phone', 'mobile_phone',
    'email', 'guarantor_name', 'guarantor_phone', 'store_name', 'hired_by', 'notes', 'created_by',
])]
class HiringReport extends Model
{
    /** @use HasFactory<HiringReportFactory> */
    use HasFactory;

    /** Les champs du contenu, ceux du formulaire (le magasin, l'employé et l'auteur se règlent à part). */
    public const FIELDS = [
        'employee_number', 'employee_name', 'furigana_last_name', 'furigana_first_name', 'gender', 'tax_classification',
        'birth_date', 'hire_date', 'education', 'postal_code', 'address', 'phone', 'mobile_phone', 'email',
        'guarantor_name', 'guarantor_phone', 'store_name', 'hired_by', 'notes',
    ];

    protected static function newFactory(): HiringReportFactory
    {
        return HiringReportFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d', 'hire_date' => 'date:Y-m-d'];
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
