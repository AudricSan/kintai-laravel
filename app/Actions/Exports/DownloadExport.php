<?php

namespace App\Actions\Exports;

use App\Activity\ActivityLogger;
use App\Exports\CalendarExportDataset;
use App\Exports\ExportDataset;
use App\Exports\ExportScope;
use App\Exports\ExportTooLargeException;
use App\Exports\ExportWriter;
use App\Models\Store;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Produit le téléchargement d'un jeu de données pour un périmètre déjà autorisé (`ExportAccess::scope()`), après avoir
 * vérifié le format et la taille, et journalise l'export. Réutilisable telle quelle par l'API.
 */
class DownloadExport
{
    public function __construct(
        private readonly ExportWriter $writer,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @throws ValidationException format non supporté ou calendrier impossible pour ce périmètre
     * @throws ExportTooLargeException trop de lignes pour ce format
     */
    public function handle(ExportDataset $dataset, ExportScope $scope, string $format): StreamedResponse
    {
        $this->check($dataset, $scope, $format);

        $filename = $this->filename($dataset, $scope, $format);

        if ($format === ExportWriter::ICS) {
            $calendar = $dataset instanceof CalendarExportDataset ? $dataset->calendar($scope) : '';
            $this->log($dataset, $scope, $format, $dataset->count($scope));

            return response()->streamDownload(function () use ($calendar): void {
                echo $calendar;
            }, $filename, ['Content-Type' => ExportWriter::CONTENT_TYPES[ExportWriter::ICS]]);
        }

        $count = $dataset->count($scope);

        $this->log($dataset, $scope, $format, $count);

        return $this->writer->response($dataset, $dataset->rows($scope), $format, $filename, $this->subtitle($scope, $dataset));
    }

    /**
     * Vérifie le format et la taille sans rien produire (aperçu avant téléchargement).
     *
     * @throws ValidationException
     * @throws ExportTooLargeException
     */
    public function check(ExportDataset $dataset, ExportScope $scope, string $format): void
    {
        if (! in_array($format, $dataset->formats(), true)) {
            throw ValidationException::withMessages(['format' => __('This format is not available for this data set.')]);
        }

        if ($format === ExportWriter::ICS) {
            if (! $dataset instanceof CalendarExportDataset || ! $dataset->canBeCalendar($scope)) {
                throw ValidationException::withMessages(['format' => __('A calendar export needs exactly one employee.')]);
            }

            return;
        }

        $count = $dataset->count($scope);
        $limit = ExportWriter::maxRows($format);
        if ($count > $limit) {
            throw new ExportTooLargeException($count, $limit);
        }
    }

    private function filename(ExportDataset $dataset, ExportScope $scope, string $format): string
    {
        $name = 'kintai-'.str_replace('_', '-', $dataset->key());
        if ($dataset->hasPeriod()) {
            $name .= '-'.$scope->from->toDateString().'_'.$scope->to->toDateString();
        }

        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $name).'.'.$format;
    }

    private function subtitle(ExportScope $scope, ExportDataset $dataset): string
    {
        $parts = [implode(', ', $this->storeNames($scope))];
        if ($dataset->hasPeriod()) {
            $parts[] = $scope->from->toDateString().' → '.$scope->to->toDateString();
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * @return list<string>
     */
    private function storeNames(ExportScope $scope): array
    {
        return Store::query()->whereIn('id', $scope->storeIds)->orderBy('name')->pluck('name')->all();
    }

    private function log(ExportDataset $dataset, ExportScope $scope, string $format, int $count): void
    {
        $properties = [
            'dataset' => $dataset->key(),
            'format' => $format,
            'count' => $count,
            'stores' => $this->storeNames($scope),
        ];

        if ($dataset->hasPeriod()) {
            $properties += ['from' => $scope->from->toDateString(), 'to' => $scope->to->toDateString()];
        }

        $this->activity->record(
            'export.downloaded',
            null,
            count($scope->storeIds) === 1 ? $scope->storeIds[0] : null,
            $properties,
            $scope->actor,
        );
    }
}
