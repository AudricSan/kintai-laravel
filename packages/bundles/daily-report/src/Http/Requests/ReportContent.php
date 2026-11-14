<?php

namespace Kintai\Bundles\DailyReport\Http\Requests;

/**
 * Règles de saisie du contenu d'un rapport journalier, communes à la création (`nullable`) et à la correction (`sometimes`).
 */
trait ReportContent
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function contentRules(bool $creating): array
    {
        $presence = $creating ? 'nullable' : 'sometimes';

        return [
            'sales_total' => [$presence, 'numeric', 'min:0', 'max:9999999999'],
            'customer_count' => [$presence, 'integer', 'min:0', 'max:1000000'],
            'labor_cost' => [$presence, 'numeric', 'min:0', 'max:9999999999'],
            'waste_total' => [$presence, 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
