<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot `store_user` : l'appartenance d'un employé à un magasin.
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property string|null $staff_code
 * @property Carbon|null $hire_date
 * @property string|null $contract_type
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StoreMembership extends Pivot
{
    protected $table = 'store_user';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }
}
