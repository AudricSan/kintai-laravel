<?php

namespace App\Exceptions;

use App\Planning\ShiftIssue;
use Kintai\Contracts\Shifts\ShiftWarnings;
use RuntimeException;

/**
 * Le shift déclenche des avertissements : rien n'est enregistré tant que l'acteur ne confirme pas (`force`).
 */
class ShiftWarningsException extends RuntimeException implements ShiftWarnings
{
    /**
     * @param  list<ShiftIssue>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(implode("\n", $this->messages()));
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(fn (ShiftIssue $issue): string => $issue->message, $this->issues);
    }
}
