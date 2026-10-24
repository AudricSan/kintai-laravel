<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class ActivityController
{
    use HasBaseUrl;

    /** Plafond de lignes exportées, pour ne pas charger un CSV en mémoire sans limite. */
    private const EXPORT_MAX_ROWS = 20000;

    /** Durées de conservation proposées à la purge manuelle, en jours (0 = tout supprimer). */
    private const PURGE_KEEP_DAYS = ['0', '7', '30', '90', '180', '365'];

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly LogRepositoryInterface $logs,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        $tab = $request->query('tab', 'activity');

        if ($tab === 'error-file') {
            return $this->errorLogTab($request);
        }

        $filters = $this->buildFilters($request);

        $page    = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 100;

        $rows         = $this->logs->findAll($page, $perPage, $filters);
        $total        = $this->logs->countAll($filters);
        $channels     = $this->logs->findChannels();
        $actions      = $this->logs->findActions();
        $resourceTypes = $this->logs->findResourceTypes();

        $levels = [
            LogRepositoryInterface::LEVEL_DEBUG,
            LogRepositoryInterface::LEVEL_INFO,
            LogRepositoryInterface::LEVEL_WARNING,
            LogRepositoryInterface::LEVEL_ERROR,
            LogRepositoryInterface::LEVEL_CRITICAL,
        ];

        $usersMap = [];
        foreach ($this->users->findAll() as $u) {
            $uid = (int) $u['id'];
            $name = $u['display_name'] ?? trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
            $usersMap[$uid] = $name ? sprintf('%s (%s)', $name, $u['email'] ?? '') : ($u['email'] ?? sprintf('user #%d', $uid));
        }

        $totalPages = (int) ceil($total / $perPage);

        return Response::html($this->view->render('system.activity-log', [
            'title'          => 'Journal d\'activité',
            'tab'            => 'activity',
            'rows'           => $rows,
            'total'          => $total,
            'page'           => $page,
            'totalPages'     => $totalPages,
            'levels'         => $levels,
            'channels'       => $channels,
            'actions'        => $actions,
            'resource_types' => $resourceTypes,
            'users_map'      => $usersMap,
            'filters'        => $filters,
            // Purge réservée à l'Owner ; le résultat de la dernière purge revient par l'URL après la redirection.
            'can_purge'      => !empty(($request->getAttribute('auth_user') ?? [])['is_admin']),
            'purged'         => $request->query('purged') !== null ? max(0, (int) $request->query('purged')) : null,
            'purge_error'    => $request->query('purge_error') !== null,
            'purge_options'  => array_map('intval', self::PURGE_KEEP_DAYS),
        ], 'layout.app'));
    }

    /**
     * POST /admin/activity/purge — l'Owner vide le journal pour libérer de l'espace et alléger les requêtes. Conserve
     * les `keep_days` derniers jours (0 = tout supprimer). La purge est elle-même journalisée, après coup, pour qu'il
     * en reste une trace même quand tout a été supprimé.
     */
    public function purge(Request $request): Response
    {
        $keepDays = (string) $request->post('keep_days', '');
        if (!in_array($keepDays, self::PURGE_KEEP_DAYS, true)) {
            return Response::redirect($this->base() . '/admin/activity?purge_error=1');
        }

        $keep   = (int) $keepDays;
        $cutoff = $keep > 0 ? date('Y-m-d H:i:s', strtotime("-{$keep} days")) : null;

        $deleted = $cutoff !== null ? $this->logs->purgeOlderThan($cutoff) : $this->logs->purgeAll();
        $this->logs->reclaimSpace();

        $this->auditLogger->log($request, 'activity_log.purged', 'system', null, [
            'keep_days' => $keep,
            'cutoff'    => $cutoff,
            'deleted'   => $deleted,
        ]);

        return Response::redirect($this->base() . '/admin/activity?purged=' . $deleted);
    }

    /** GET /admin/activity/export — export CSV du journal, avec les mêmes filtres que index(). */
    public function export(Request $request): Response
    {
        $filters = $this->buildFilters($request);
        $rows    = $this->logs->findAll(1, self::EXPORT_MAX_ROWS, $filters);

        $usersMap = [];
        foreach ($this->users->findAll() as $u) {
            $uid = (int) $u['id'];
            $name = $u['display_name'] ?? trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
            $usersMap[$uid] = $name ?: ($u['email'] ?? sprintf('#%d', $uid));
        }

        $buf = fopen('php://memory', 'r+');
        fprintf($buf, "\xEF\xBB\xBF");
        $put = fn(array $row) => fputcsv($buf, $row, ';', '"', '\\');

        $put(['Date', 'Niveau', 'Canal', 'Action', 'Ressource', 'Ressource ID', 'Message', 'Utilisateur', 'Store ID', 'IP', 'Méthode', 'URI', 'Statut', 'Durée (ms)']);
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $put([
                $row['created_at'] ?? '',
                $row['level'] ?? '',
                $row['channel'] ?? '',
                $row['action'] ?? '',
                $row['resource_type'] ?? '',
                $row['resource_id'] ?? '',
                $row['message'] ?? '',
                $uid > 0 ? ($usersMap[$uid] ?? '#' . $uid) : '',
                $row['store_id'] ?? '',
                $row['ip_address'] ?? '',
                $row['request_method'] ?? '',
                $row['request_uri'] ?? '',
                $row['response_status'] ?? '',
                $row['duration_ms'] ?? '',
            ]);
        }

        rewind($buf);
        $csv = stream_get_contents($buf);
        fclose($buf);

        $this->auditLogger->log($request, 'activity_log.exported', 'system', null, [
            'filters' => $filters,
            'rows'    => count($rows),
        ]);

        return Response::csv($csv, 'journal-activite-' . date('Y-m-d') . '.csv');
    }

    /** @return array<string, mixed> */
    private function buildFilters(Request $request): array
    {
        $filters = [
            'level'         => $request->query('level'),
            'channel'       => $request->query('channel'),
            'action'        => $request->query('action'),
            'resource_type' => $request->query('resource_type'),
            'user_id'       => (int) ($request->query('user_id') ?? 0) ?: null,
            'from'          => $request->query('from'),
            'to'            => $request->query('to'),
            'query'         => $request->query('query'),
            'store_ids'     => $request->getAttribute('managed_store_ids'),
        ];

        return array_filter($filters, fn($v) => $v !== null && $v !== '');
    }

    private function errorLogTab(Request $request): Response
    {
        $lines = max(10, min(5000, (int) ($request->query('lines') ?? 200)));
        $path  = dirname(__DIR__, 4) . '/storage/logs/error.log';

        $content = [];
        if (file_exists($path)) {
            $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($all !== false) {
                $content = array_slice($all, -$lines);
            }
        }

        return Response::html($this->view->render('system.activity-log', [
            'title'      => 'Fichier error.log',
            'tab'        => 'error-file',
            'err_content' => $content,
            'err_lines'  => $lines,
            'err_path'   => $path,
            'err_size'   => file_exists($path) ? filesize($path) : 0,
        ], 'layout.app'));
    }
}
