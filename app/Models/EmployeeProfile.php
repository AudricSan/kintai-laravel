<?php

namespace App\Models;

use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Données RH sensibles d'un employé (1-1 avec users).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $gender
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $mobile_phone
 * @property string|null $tax_classification
 * @property string|null $education
 * @property string|null $guarantor_name
 * @property string|null $guarantor_phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'gender', 'birth_date', 'address', 'postal_code', 'phone', 'mobile_phone',
    'tax_classification', 'education', 'guarantor_name', 'guarantor_phone',
])]
class EmployeeProfile extends Model
{
    /** @use HasFactory<EmployeeProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
