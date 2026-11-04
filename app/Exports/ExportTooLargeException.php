<?php

namespace App\Exports;

use RuntimeException;

/**
 * Le périmètre dépasse le nombre de lignes exportable dans ce format.
 */
class ExportTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $rows, public readonly int $limit)
    {
        parent::__construct("Export of {$rows} rows exceeds the limit of {$limit}.");
    }

    /**
     * Message affiché à l'utilisateur.
     */
    public function userMessage(): string
    {
        return __('This export has :rows rows, more than the :limit rows allowed in this format. Narrow the period, the stores or the employees.', [
            'rows' => number_format($this->rows, 0, '.', ' '),
            'limit' => number_format($this->limit, 0, '.', ' '),
        ]);
    }
}
