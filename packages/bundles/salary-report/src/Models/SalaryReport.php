<?php

namespace Kintai\Bundles\SalaryReport\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Le rapport de salaire d'un mois, pour un magasin entier (`user_id` nul) ou pour un seul employé. Donnée très sensible :
 * ne se lit qu'à travers `SalaryReportAccess`.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property string $target_month
 * @property int|null $person_in_charge_id
 * @property numeric-string $total_payment
 * @property numeric-string $total_deductions
 * @property numeric-string $income_tax_base
 * @property numeric-string $withholding_tax
 * @property numeric-string $residence_tax
 * @property numeric-string $other_deductions
 * @property numeric-string $net_payment
 * @property int $active_employees
 * @property numeric-string $hand_delivered_salary
 * @property numeric-string $bank_transfer_salary
 * @property numeric-string $staff_man_hours
 * @property numeric-string $staff_total_payment
 * @property numeric-string $staff_avg_hourly_wage
 * @property list<array{user_id: int, minutes: int}>|null $employee_hours
 * @property int $new_hires
 * @property int $resigned_staff
 * @property string|null $hire_registrations
 * @property string|null $remarks
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $employee
 * @property-read User|null $personInCharge
 * @property-read User|null $creator
 */
#[Fillable([
    'store_id', 'user_id', 'target_month', 'person_in_charge_id', 'total_payment', 'total_deductions', 'income_tax_base',
    'withholding_tax', 'residence_tax', 'other_deductions', 'net_payment', 'active_employees', 'hand_delivered_salary',
    'bank_transfer_salary', 'staff_man_hours', 'staff_total_payment', 'staff_avg_hourly_wage', 'employee_hours', 'new_hires',
    'resigned_staff', 'hire_registrations', 'remarks', 'created_by',
])]
class SalaryReport extends Model
{
    /** Montants en devise du magasin, modifiables à la main. */
    public const AMOUNTS = [
        'total_payment', 'total_deductions', 'income_tax_base', 'withholding_tax', 'residence_tax', 'other_deductions',
        'net_payment', 'hand_delivered_salary', 'bank_transfer_salary', 'staff_total_payment', 'staff_avg_hourly_wage',
    ];

    /** Décimaux qui ne sont pas des montants. */
    public const HOURS = ['staff_man_hours'];

    public const COUNTS = ['active_employees', 'new_hires', 'resigned_staff'];

    protected $table = 'salary_reports';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_payment' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'income_tax_base' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'residence_tax' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_payment' => 'decimal:2',
            'active_employees' => 'integer',
            'hand_delivered_salary' => 'decimal:2',
            'bank_transfer_salary' => 'decimal:2',
            'staff_man_hours' => 'decimal:2',
            'staff_total_payment' => 'decimal:2',
            'staff_avg_hourly_wage' => 'decimal:2',
            'employee_hours' => 'array',
            'new_hires' => 'integer',
            'resigned_staff' => 'integer',
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
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function personInCharge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'person_in_charge_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Premier et dernier jour du mois du rapport.
     *
     * @return array{0: string, 1: string}
     */
    public function period(): array
    {
        $first = Carbon::parse($this->target_month.'-01');

        return [$first->toDateString(), $first->copy()->endOfMonth()->toDateString()];
    }
}
