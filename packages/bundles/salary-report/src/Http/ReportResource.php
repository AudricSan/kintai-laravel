<?php

namespace Kintai\Bundles\SalaryReport\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;

/**
 * Un rapport tel que l'API (et l'export JSON) le rend : tableau préparé par `payload()`.
 *
 * @property array<string, mixed> $resource
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * Les champs d'un rapport. Les noms viennent des relations chargées : aucun e-mail, aucune donnée du compte.
     *
     * @return array<string, mixed>
     */
    public static function payload(SalaryReport $row): array
    {
        $row->loadMissing(['store:id,name', 'employee:id,name', 'personInCharge:id,name']);

        return [
            'id' => $row->id,
            'store' => ['id' => $row->store_id, 'name' => $row->store->name],
            'employee' => $row->employee === null ? null : ['id' => $row->employee->id, 'name' => $row->employee->name],
            'target_month' => $row->target_month,
            'person_in_charge' => $row->personInCharge === null ? null : ['id' => $row->personInCharge->id, 'name' => $row->personInCharge->name],
            'total_payment' => $row->total_payment,
            'total_deductions' => $row->total_deductions,
            'income_tax_base' => $row->income_tax_base,
            'withholding_tax' => $row->withholding_tax,
            'residence_tax' => $row->residence_tax,
            'other_deductions' => $row->other_deductions,
            'net_payment' => $row->net_payment,
            'active_employees' => $row->active_employees,
            'hand_delivered_salary' => $row->hand_delivered_salary,
            'bank_transfer_salary' => $row->bank_transfer_salary,
            'staff_man_hours' => $row->staff_man_hours,
            'staff_total_payment' => $row->staff_total_payment,
            'staff_avg_hourly_wage' => $row->staff_avg_hourly_wage,
            'employee_hours' => $row->employee_hours ?? [],
            'new_hires' => $row->new_hires,
            'resigned_staff' => $row->resigned_staff,
            'hire_registrations' => $row->hire_registrations,
            'remarks' => $row->remarks,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
