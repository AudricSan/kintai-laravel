<?php

namespace App\Actions\Exports;

use App\Activity\ActivityLogger;
use App\Exports\Datasets\ShiftsDataset;
use App\Exports\ExportScope;
use App\Exports\ExportWriter;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Exporter mon planning » : les shifts de l'utilisateur dans un de ses magasins, sur une période, en CSV, JSON ou
 * ICS. Toujours son propre planning : aucun identifiant d'employé n'est lu dans la requête.
 */
class ExportOwnSchedule
{
    public const FORMATS = ['csv', 'json', 'ics'];

    /** Période par défaut quand la fin est omise, en jours. */
    public const DEFAULT_DAYS = 90;

    /** Période maximale, en jours. */
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly ShiftsDataset $shifts,
        private readonly ExportWriter $writer,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Borne la période : dates invalides ou absentes = aujourd'hui et +90 jours, bornes inversées remises dans l'ordre,
     * durée plafonnée à 366 jours.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(?string $from, ?string $to, ?CarbonImmutable $today = null): array
    {
        $start = $this->day($from) ?? ($today ?? CarbonImmutable::today());
        $end = $this->day($to) ?? $start->addDays(self::DEFAULT_DAYS);

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $limit = $start->addDays(self::MAX_DAYS);

        return [$start, $end->greaterThan($limit) ? $limit : $end];
    }

    /**
     * @throws ValidationException format inconnu
     */
    public function handle(User $user, Store $store, string $format, ?string $from, ?string $to): StreamedResponse
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => __('This format is not available for this data set.')]);
        }

        [$start, $end] = $this->period($from, $to);
        $scope = new ExportScope($user, true, [$store->id], [$user->id], [], $start, $end);
        $count = $this->shifts->count($scope);

        $this->activity->record('export.schedule', null, $store->id, [
            'format' => $format,
            'count' => $count,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ], $user);

        $name = 'kintai-'.preg_replace('/[^A-Za-z0-9_-]/', '', $store->code).'-'.$start->toDateString().'_'.$end->toDateString().'.'.$format;

        return match ($format) {
            'ics' => response()->streamDownload(function () use ($scope): void {
                echo $this->shifts->calendar($scope);
            }, $name, ['Content-Type' => ExportWriter::CONTENT_TYPES[ExportWriter::ICS]]),
            'json' => response()->streamDownload(function () use ($scope, $store, $start, $end): void {
                $rows = iterator_to_array($this->shifts->rows($scope), false);
                echo json_encode([
                    'store' => ['id' => $store->id, 'name' => $store->name, 'timezone' => (string) config('kintai.timezone')],
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
                    'count' => count($rows),
                    'shifts' => $rows,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }, $name, ['Content-Type' => 'application/json; charset=utf-8']),
            default => $this->writer->response($this->shifts, $this->shifts->rows($scope), ExportWriter::CSV, $name),
        };
    }

    private function day(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }
}
