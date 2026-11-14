<?php

namespace Kintai\Bundles\DailyReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un rapport journalier : magasin, date, puis le contenu.
 */
class StoreRequest extends FormRequest
{
    use ReportContent;

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
            'store_id' => ['required', 'integer'],
            'report_date' => ['required', 'date_format:Y-m-d'],
        ] + $this->contentRules(true);
    }
}
