<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AuthService;
use App\Exceptions\NotFoundException;
use App\Extension\Absences;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\IcalService;
use App\Services\ScheduleExportService;

/**
 * Téléchargement de son propre horaire (JSON, CSV ou ICS) depuis l'onglet « iCal » du profil.
 *
 * Toujours l'horaire de l'utilisateur connecté, dans un magasin dont il est membre : aucun identifiant d'employé n'est lu
 * dans la requête. Contrairement au flux iCal (jeton public, relu par un agenda), c'est un fichier ponctuel, derrière la session.
 */
final class ScheduleExportController
{
    use HasBaseUrl;

    public function __construct(
        private readonly AuthService                  $auth,
        private readonly StoreRepositoryInterface     $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly ScheduleExportService        $export,
        private readonly IcalService                  $ical,
        private readonly AuditLogger                  $auditLogger,
    ) {}

    /** GET /profile/schedule-export?format=json|csv|ics&store_id=&from=&to= */
    public function download(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return redirect()->away($this->base() . '/login');
        }

        $format = strtolower((string) $request->query('format', ''));
        if (!in_array($format, ScheduleExportService::FORMATS, true)) {
            return redirect()->away($this->base() . '/profile?tab=ical');
        }

        $userId  = (int) $user['id'];
        $storeId = (int) $request->query('store_id', 0);
        $store   = $storeId > 0 ? $this->stores->findById($storeId) : null;
        if ($store === null || $this->storeUsers->findMembership($storeId, $userId) === null) {
            throw new NotFoundException(__('error_resource_not_found'));
        }

        $from = $request->query('from');
        $to   = $request->query('to');
        $period = $this->export->period(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            date('Y-m-d'),
        );

        $mine = array_values(array_filter(
            Shift::findByUser($userId),
            static fn(array $s): bool => (int) ($s['store_id'] ?? 0) === $storeId
        ));
        $shifts = $this->export->select($mine, $period['from'], $period['to']);

        $types = [];
        foreach (ShiftType::findAll() as $t) {
            $types[(int) $t['id']] = $t;
        }

        $filename = 'kintai-' . ($store['code'] ?? $storeId) . '-' . $period['from'] . '_' . $period['to'] . '.' . $format;
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        $this->auditLogger->log($request, 'export.schedule', 'user', $userId, [
            'store_id' => $storeId,
            'format'   => $format,
            'from'     => $period['from'],
            'to'       => $period['to'],
            'count'    => count($shifts),
        ], $storeId, $userId);

        if ($format === 'ics') {
            // Congés approuvés de la période, comme dans le flux : l'agenda importé montre aussi les absences.
            $timeoffs = array_values(array_filter(
                Absences::current()->forUser($userId),
                static fn(array $t): bool => (int) ($t['store_id'] ?? 0) === $storeId
                    && ($t['status'] ?? '') === 'approved'
                    && (string) ($t['end_date'] ?? '') >= $period['from']
                    && (string) ($t['start_date'] ?? '') <= $period['to']
            ));

            return response()->ical($this->ical->build($shifts, $timeoffs, $store, $user, $types), $filename);
        }

        $rows = $this->export->rows($shifts, $store, $types);

        if ($format === 'csv') {
            return response()->csv($this->export->csv($rows), $filename);
        }

        return response()->jsonDownload($this->export->json($rows, $store, $period['from'], $period['to']), $filename);
    }
}
