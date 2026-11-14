<?php

namespace Kintai\Bundles\DailyReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Bundles\DailyReport\Models\DailyReport;

/**
 * Filtres de la liste des rapports journaliers.
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer'],
            'author_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(DailyReport::STATUSES)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
