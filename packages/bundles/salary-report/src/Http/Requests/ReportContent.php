<?php

namespace Kintai\Bundles\SalaryReport\Http\Requests;

use Kintai\Bundles\SalaryReport\Models\SalaryReport;

/**
 * Règles de saisie du contenu d'un rapport (montants, heures, compteurs, remarques), communes à la création et à la correction.
 */
trait ReportContent
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function contentRules(): array
    {
        $rules = [];

        foreach ([...SalaryReport::AMOUNTS, ...SalaryReport::HOURS] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'];
        }

        foreach (SalaryReport::COUNTS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'];
        }

        return $rules + [
            'person_in_charge_id' => ['sometimes', 'nullable', 'integer'],
            'hire_registrations' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
