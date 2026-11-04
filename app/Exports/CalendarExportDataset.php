<?php

namespace App\Exports;

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
