<?php

namespace App\Exports;

use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportScope;

/**
 * Jeu de données qui sait aussi se publier en calendrier (format `ics`).
 */
interface CalendarExportDataset extends ExportDataset
{
    /**
     * Calendrier iCal du périmètre.
     */
    public function calendar(ExportScope $scope): string;

    /**
     * Le périmètre peut-il devenir un calendrier ? (le Core exige une personne et un magasin)
     */
    public function canBeCalendar(ExportScope $scope): bool;
}
