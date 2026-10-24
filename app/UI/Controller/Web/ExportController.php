<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Contract\Extension\ExportDataset;
use kintai\Contract\Provider\ExportQuery;
use kintai\Core\Auth\AuthService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Export\ExportAccess;
use kintai\Core\Export\ExportWriter;
use kintai\Core\Extension\Absences;
use kintai\Core\Extension\ExportDatasets;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\IcalService;
use kintai\Core\Services\ScheduleExportService;
use kintai\Core\Services\UserSelection;
use kintai\UI\ViewRenderer;

/**
 * Page « Exports » : n'importe quel jeu de données de l'application (Core et bundles actifs) en JSON, CSV ou classeur Excel,
 * pour soi, pour des personnes choisies, pour un magasin ou pour tous ceux dont on a la charge. L'horaire peut aussi sortir en
 * ICS quand il porte sur une seule personne dans un seul magasin.
 *
 * Les droits sont décidés par ExportAccess (permission du jeu de données dans chaque magasin) ; ce contrôleur ne fait que
 * lire la demande, appeler le fournisseur de lignes avec le périmètre autorisé et tracer l'export.
 */
final class ExportController
{
    use HasBaseUrl;

    /** Cible « tous mes magasins » dans le sélecteur ; « me » et « store:ID » complètent. */
    private const TARGET_ALL = 'all';

    public function __construct(
        private readonly ViewRenderer                 $view,
        private readonly AuthService                  $auth,
        private readonly ExportAccess                 $access,
        private readonly ExportWriter                 $writer,
        private readonly UserSelection                $selection,
        private readonly StoreRepositoryInterface     $stores,
        private readonly ShiftRepositoryInterface     $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly ScheduleExportService        $schedule,
        private readonly IcalService                  $ical,
        private readonly AuditLogger                  $auditLogger,
    ) {}

    /** GET /exports?dataset=&target= */
    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $available = $this->access->available($user, ExportDatasets::current()->all());
        $key       = (string) $request->query('dataset', '');
        if (!isset($available[$key])) {
            $key = (string) (array_key_first($available) ?? '');
        }
        $entry   = $available[$key] ?? null;
        $targets = $entry !== null ? $this->targets($entry['stores'], $entry['self']) : [];
        $target  = (string) $request->query('target', '');
        if (!isset($targets[$target])) {
            $target = (string) (array_key_first($targets) ?? '');
        }

        // Les personnes proposables sont celles du magasin choisi ; « moi » et « tous les magasins » n'en choisissent pas.
        $candidates = [];
        if ($entry !== null && $entry['dataset']->perPerson && str_starts_with($target, 'store:')) {
            $candidates = $this->selection->candidates((int) substr($target, 6));
        }

        return Response::html($this->view->render('exports.index', [
            'title'      => __('export_title'),
            'available'  => $available,
            'dataset'    => $entry['dataset'] ?? null,
            'targets'    => $targets,
            'target'     => $target,
            'candidates' => $candidates,
            'from'       => date('Y-m-01'),
            'to'         => date('Y-m-d', strtotime('+90 days')),
            'canIcs'     => ($entry['dataset']->key ?? '') === 'shifts',
        ]));
    }

    /** GET /exports/download?dataset=&target=&user_ids[]=&from=&to=&format= */
    public function download(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $dataset = ExportDatasets::current()->find((string) $request->query('dataset', ''));
        $format  = strtolower((string) $request->query('format', ''));
        if ($dataset === null || !in_array($format, [...ExportWriter::FORMATS, 'ics'], true)) {
            return Response::redirect($this->base() . '/exports');
        }
        if ($format === 'ics' && $dataset->key !== 'shifts') {
            return Response::redirect($this->base() . '/exports');
        }

        [$scope, $storeId] = $this->parseTarget((string) $request->query('target', ''));
        $ids    = $request->query('user_ids', []);
        $from   = $request->query('from');
        $to     = $request->query('to');
        $period = $this->schedule->period(is_string($from) ? $from : null, is_string($to) ? $to : null, date('Y-m-d'));

        try {
            $query = $this->access->query(
                $user, $dataset, $scope, $storeId,
                is_array($ids) ? array_map('intval', $ids) : [],
                $period['from'], $period['to'], user_locale($user),
            );
        } catch (ForbiddenException) {
            return Response::redirect($this->base() . '/exports?error=forbidden');
        }

        $basename = 'kintai-' . $dataset->key . ($dataset->hasPeriod ? '-' . $period['from'] . '_' . $period['to'] : '');

        if ($format === 'ics') {
            $ics = $this->ics($query, $user);
            if ($ics === null) {
                return Response::redirect($this->base() . '/exports?error=ics_scope');
            }
            $this->trace($request, $user, $dataset, $scope, $query->storeIds, 'ics', $ics['count']);

            return Response::ical($ics['body'], $basename . '.ics');
        }

        $rows = [...$dataset->provider->rows($query)];
        $this->trace($request, $user, $dataset, $scope, $query->storeIds, $format, count($rows));

        $meta = ['stores' => $query->storeIds];
        if ($dataset->hasPeriod) {
            $meta += ['from' => $period['from'], 'to' => $period['to']];
        }

        return $this->writer->response($dataset, $rows, $format, $basename, $meta);
    }

    /**
     * Cibles proposées : « moi », chaque magasin autorisé, puis « tous mes magasins » quand il y en a plusieurs.
     *
     * @param list<int> $storeIds
     * @return array<string, string> valeur => libellé
     */
    private function targets(array $storeIds, bool $self): array
    {
        $targets = [];
        if ($self) {
            $targets['me'] = __('export_target_me');
        }
        foreach ($storeIds as $id) {
            $targets['store:' . $id] = (string) ($this->stores->findById($id)['name'] ?? ('#' . $id));
        }
        if (count($storeIds) > 1) {
            $targets[self::TARGET_ALL] = __('export_target_all');
        }

        return $targets;
    }

    /** @return array{0: string, 1: int|null} */
    private function parseTarget(string $target): array
    {
        if (str_starts_with($target, 'store:')) {
            return [ExportAccess::SCOPE_STORE, (int) substr($target, 6)];
        }

        return [$target === self::TARGET_ALL ? ExportAccess::SCOPE_ALL : ExportAccess::SCOPE_ME, null];
    }

    /**
     * Horaire d'une seule personne dans un seul magasin au format ICS (les événements d'un agenda sont ceux d'une personne).
     *
     * @param array<string, mixed> $user
     * @return array{body: string, count: int}|null null si le périmètre ne désigne pas exactement une personne et un magasin
     */
    private function ics(ExportQuery $query, array $user): ?array
    {
        if ($query->userIds === null || count($query->userIds) !== 1 || count($query->storeIds) !== 1) {
            return null;
        }
        $personId = $query->userIds[0];
        $storeId  = $query->storeIds[0];
        $store    = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_resource_not_found'));
        }
        $person = $personId === (int) $user['id']
            ? $user
            : ['id' => $personId] + ($this->selection->resolve($storeId, [$personId])[0] ?? []);

        $mine = array_values(array_filter(
            $this->shifts->findByUser($personId),
            static fn(array $s): bool => (int) ($s['store_id'] ?? 0) === $storeId
        ));
        $shifts = $this->schedule->select($mine, $query->from, $query->to);

        $types = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $types[(int) $t['id']] = $t;
        }
        $timeoffs = array_values(array_filter(
            Absences::current()->forUser($personId),
            static fn(array $t): bool => (int) ($t['store_id'] ?? 0) === $storeId
                && ($t['status'] ?? '') === 'approved'
                && (string) ($t['end_date'] ?? '') >= $query->from
                && (string) ($t['start_date'] ?? '') <= $query->to
        ));

        return ['body' => $this->ical->build($shifts, $timeoffs, $store, $person, $types), 'count' => count($shifts)];
    }

    /**
     * Trace de l'export dans le journal d'audit : qui, quoi, pour quels magasins, combien de lignes.
     *
     * @param array<string, mixed> $user
     * @param list<int>            $storeIds
     */
    private function trace(Request $request, array $user, ExportDataset $dataset, string $scope, array $storeIds, string $format, int $count): void
    {
        $this->auditLogger->log($request, 'export.dataset', 'export', 0, [
            'dataset' => $dataset->key,
            'scope'   => $scope,
            'stores'  => $storeIds,
            'format'  => $format,
            'count'   => $count,
        ], count($storeIds) === 1 ? $storeIds[0] : null, (int) $user['id']);
    }
}
