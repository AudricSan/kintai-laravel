<?php

namespace Kintai\Bundles\SalaryReport\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un employé d'un magasin et son assujettissement aux retenues (sans ligne : non assujetti).
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property bool $subject_to_deductions
 */
#[Fillable(['store_id', 'user_id', 'subject_to_deductions'])]
class SalaryReportMember extends Model
{
    protected $table = 'salary_report_members';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['subject_to_deductions' => 'boolean'];
    }
}
