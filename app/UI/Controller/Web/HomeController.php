<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Contract\Extension\DashboardWidget;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Extension\DashboardLayout;
use kintai\Core\Extension\DashboardWidgets;
use kintai\Core\Extension\PendingRequests;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Extension\Attendances;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\DashboardAlertService;
use kintai\Core\Services\DashboardLayoutResolver;
use kintai\Core\Services\ShiftWageCalculator;
use kintai\Core\Services\StoreStatsServiceInterface;
use kintai\UI\ViewRenderer;

final class HomeController
{
    public const ADMIN_WIDGETS = [
        'kpi_counters',
        'dashboard_alerts',
        'store_stats_summary',
        'financial_overview',
        'hr_absenteeism',
        'shifts_today',
    ];

    /** Largeurs par défaut des widgets du gérant : Finances et RH côte à côte, les autres en pleine largeur. */
    public const ADMIN_WIDGET_WIDTHS = [
        'financial_overview' => DashboardLayout::HALF,
        'hr_absenteeism'     => DashboardLayout::HALF,
    ];

    /**
     * Préfixe des widgets employé proposés sur le tableau de bord gérant : un gérant peut y ajouter ses widgets
     * personnels (« emp_shifts_today » = ses shifts du jour), l'inverse n'est pas proposé. Le préfixe évite la
     * collision avec les clés du gérant (« shifts_today » = tous les shifts du jour).
     */
    public const EMPLOYEE_WIDGET_PREFIX = 'emp_';

    /** Fenêtre glissante (jours) du widget "Aperçu statistiques", alignée sur la période "30 jours" des pages analytics. */
    private const STATS_WIDGET_PERIOD_DAYS = 30;

    /** Fenêtre glissante (jours) pour la tendance mensuelle du widget financier (~6 mois). */
    private const FINANCIAL_TREND_PERIOD_DAYS = 180;

    /** Écart (minutes) entre l'heure de pointage réelle et l'heure de début du shift au-delà duquel un pointage est compté "en retard". */
    private const HR_LATE_THRESHOLD_MINUTES = 5;

    /** Heures nettes hebdomadaires au-delà desquelles une semaine (utilisateur) est comptée en heures supplémentaires. */
    private const HR_OVERTIME_WEEKLY_HOURS = 40;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly UserDashboardPrefsRepositoryInterface $dashboardPrefs,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly StoreStatsServiceInterface $storeStats,
        private readonly DashboardAlertService $dashboardAlerts,
        private readonly PermissionService $permissions,
        private readonly ?PendingRequests $pendingRequests = null,
        // Disposition de l'utilisateur, sinon défaut de son rôle ; mises en page nommées (null : disposition enregistrée seule).
        private readonly ?DashboardLayoutResolver $layoutResolver = null,
    ) {}

    public function index(Request $request): Response
    {
        $user  = $request->getAttribute('auth_user');
        $today = date('Y-m-d');

        // Manager restreint à un sous-ensemble de stores (PermissionMiddleware) : null = admin
        // global, aucune restriction. Toutes les données du dashboard doivent être
        // bornées à cette liste pour éviter de divulguer des données d'autres stores.
        $managedStoreIds = $request->getAttribute('managed_store_ids');
        $inScope = fn(int $storeId): bool => $managedStoreIds === null || in_array($storeId, $managedStoreIds, true);

        $allUsers = $this->users->findAll();
        if ($managedStoreIds !== null) {
            $scopedUserIds = [];
            foreach ($managedStoreIds as $sid) {
                foreach ($this->storeUsers->findByStore($sid) as $su) {
                    $scopedUserIds[(int) $su['user_id']] = true;
                }
            }
            $allUsers = array_values(array_filter($allUsers, fn($u) => isset($scopedUserIds[(int) $u['id']])));
        }

        $allStores   = array_values(array_filter($this->stores->findAll(), fn($s) => $inScope((int) $s['id'])));
        $shiftsToday = array_values(array_filter($this->shifts->findAllByDate($today), fn($s) => $inScope((int) ($s['store_id'] ?? 0))));

        $storesMap = [];
        foreach ($allStores as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }
        $usersMap = [];
        foreach ($allUsers as $u) {
            $usersMap[(int) $u['id']] = $u['display_name'] ?? $u['email'] ?? ('#' . $u['id']);
        }

        $shiftsToday = array_map(function (array $s) use ($storesMap, $usersMap): array {
            $s['store_name'] = $storesMap[(int) ($s['store_id'] ?? 0)] ?? ('Store #' . (int) ($s['store_id'] ?? 0));
            $s['user_name']  = $usersMap[(int)  ($s['user_id']  ?? 0)] ?? ('User #'  . (int) ($s['user_id']  ?? 0));
            return $s;
        }, $shiftsToday);

        $validSorts = ['start_asc', 'start_desc', 'end_asc', 'end_desc', 'user_asc', 'user_desc', 'store_asc', 'store_desc', 'pause_asc', 'pause_desc'];
        $sort = $request->query('sort', 'start_asc');
        if (!in_array($sort, $validSorts, true)) {
            $sort = 'start_asc';
        }
        [$sortField, $sortDir] = explode('_', $sort, 2);
        usort($shiftsToday, function (array $a, array $b) use ($sortField, $sortDir): int {
            $va = match ($sortField) {
                'start' => $a['start_time'] ?? '',
                'end'   => $a['end_time'] ?? '',
                'user'  => $a['user_name'] ?? '',
                'store' => $a['store_name'] ?? '',
                'pause' => (int) ($a['pause_minutes'] ?? 0),
                default => '',
            };
            $vb = match ($sortField) {
                'start' => $b['start_time'] ?? '',
                'end'   => $b['end_time'] ?? '',
                'user'  => $b['user_name'] ?? '',
                'store' => $b['store_name'] ?? '',
                'pause' => (int) ($b['pause_minutes'] ?? 0),
                default => '',
            };
            $cmp = is_int($va) ? ($va <=> $vb) : strcmp((string) $va, (string) $vb);
            return $sortDir === 'desc' ? -$cmp : $cmp;
        });

        // Pointages actifs en ce moment

        // Widgets proposés : ceux du Core et ceux des bundles actifs (Bundle::dashboardWidgets(), contrat 1.4). Les
        // widgets historiques d'un bundle qui déclare les siens ne sont plus rendus par le Core ($enabledWidgets).
        $dashWidgets = DashboardWidgets::current();
        $widgetKeys  = self::offeredWidgetKeys($dashWidgets);
        $sizes       = $dashWidgets->sizes(DashboardWidget::MANAGER, self::ADMIN_WIDGETS);
        $saved = $this->dashboardPrefs->getEnabledWidgets((int) $user['id'], 'admin');
        $enabledAll = array_flip(array_values(
            $saved !== null
                ? array_intersect($saved, $widgetKeys)
                : self::defaultWidgetKeys($dashWidgets)
        ));
        $enabledWidgets = $enabledAll;

        // Aperçu statistiques (heures, coût, scores) : même permission que les pages analytics
        // complètes (admin.stores.stats), calculé uniquement si le widget est actif pour éviter
        // le coût de multiStoreComparison() sur chaque chargement du dashboard.
        $canViewStats = !empty($user['is_admin']) || $this->permissions->can($user, 'payroll.view', null);
        $storeStatsRows = [];
        $storeStatsHoursByWeek = [];
        if ($canViewStats && array_key_exists('store_stats_summary', $enabledWidgets)) {
            $statsStoreIds  = $managedStoreIds ?? array_column($allStores, 'id');
            $storeStatsRows = $this->storeStats->multiStoreComparison($statsStoreIds, self::STATS_WIDGET_PERIOD_DAYS);
            // Tendance hebdomadaire : uniquement pour un seul store géré, sinon "heures par
            // semaine" mélangerait plusieurs stores sans les distinguer (peu lisible en graphique).
            if (count($storeStatsRows) === 1) {
                $storeStatsHoursByWeek = $this->storeStats
                    ->storeStats((int) $storeStatsRows[0]['store_id'], self::STATS_WIDGET_PERIOD_DAYS)['hoursByWeek'] ?? [];
            }
        }

        // Alertes actionnables : shifts non pourvus, employés sans planning, demandes/pointages en retard.
        $dashboardAlerts = null;
        if (array_key_exists('dashboard_alerts', $enabledWidgets)) {
            $alertStoreIds   = $managedStoreIds ?? array_column($allStores, 'id');
            $dashboardAlerts = $this->dashboardAlerts->build($alertStoreIds, $storesMap);
        }

        // Vue d'ensemble financière : masse salariale du mois en cours vs précédent + tendance 6 mois.
        $financialOverview = null;
        if ($canViewStats && array_key_exists('financial_overview', $enabledWidgets)) {
            $finStoreIds = $managedStoreIds ?? array_column($allStores, 'id');
            $financialOverview = $this->buildFinancialOverview($finStoreIds);
        }

        // Absentéisme / RH : congés pris, retards, heures supplémentaires sur la période "30 jours".
        $hrStats = null;
        if ($canViewStats && array_key_exists('hr_absenteeism', $enabledWidgets)) {
            $hrStoreIds = $managedStoreIds ?? array_column($allStores, 'id');
            $hrStats    = $this->buildHrStats($hrStoreIds);
        }

        $userCan = fn(string $perm): bool => $this->permissions->can($user, $perm, null);

        // Compteur « Demandes en attente » : toutes sortes de demandes visibles (congés, échanges, candidatures…), lues
        // à travers les fournisseurs (kintai\Core\Extension\PendingRequests — Bundle::pendingRequestsProviders(),
        // contrat 1.9, ou repli historique), comme la page « Demandes ».
        $pending        = $this->pendingRequests ?? PendingRequests::current();
        $canSeeRequests = fn(string $perm): bool => !empty($user['is_admin']) || $userCan($perm);
        $requestsFeat   = $this->storeFeatureCheck();

        $bundleWidgets = $dashWidgets->render(
            DashboardWidget::MANAGER,
            self::ADMIN_WIDGETS,
            array_keys($enabledAll),
            [
                'user'              => $user,
                'store_ids'         => array_map('intval', $managedStoreIds ?? array_column($allStores, 'id')),
                'managed_store_ids' => $managedStoreIds,
                'stores_map'        => $storesMap,
                'today'             => $today,
            ],
            $this->view,
            $userCan,
            $this->storeFeatureCheck(),
        );

        $employeeWidgets = $this->employeeWidgets($dashWidgets, $user, array_keys($enabledAll), $today, $userCan);

        return Response::html($this->view->render('dashboard.index', [
            'title'              => 'Dashboard',
            'stats'              => [
                'users'            => count($allUsers),
                'stores'           => count($allStores),
                'shifts_today'     => count($shiftsToday),
                'pending_requests' => $pending->count($managedStoreIds, $canSeeRequests, $requestsFeat),
            ],
            'shifts_today'       => $shiftsToday,
            'pending_requests_visible' => $pending->hasVisible($canSeeRequests, $requestsFeat),
            'users_map'          => $usersMap,
            'sort'               => $sort,
            'store_stats_rows'   => $storeStatsRows,
            'store_stats_period' => self::STATS_WIDGET_PERIOD_DAYS,
            'store_stats_hours_by_week' => $storeStatsHoursByWeek,
            'dashboard_alerts'   => $dashboardAlerts,
            'financial_overview' => $financialOverview,
            'hr_stats'           => $hrStats,
            'user_can'           => $userCan,

            'enabled_widgets'     => $enabledWidgets,
            'enabled_widgets_all' => $enabledAll,
            'all_widgets'         => $widgetKeys,
            'widget_layout'       => DashboardLayout::resolve($widgetKeys, $this->layoutResolver?->layoutFor((int) $user['id'], 'admin') ?? $this->dashboardPrefs->getLayout((int) $user['id'], 'admin'), self::ADMIN_WIDGET_WIDTHS + $sizes['widths'], $sizes['heights']),
            'dashboard_layouts'   => $this->layoutResolver?->namedLayouts((int) $user['id'], 'admin') ?? [],
            'dashboard_layouts_route' => 'admin.dashboard.layouts',
            'dashboard_roles'     => !empty($user['is_admin']) ? ($this->layoutResolver?->roleOptions('admin') ?? []) : [],
            'widget_limits'       => $sizes['limits'],
            'widget_refresh'      => $sizes['refresh'],
            'bundle_widgets'      => $bundleWidgets,
            'bundle_widget_meta'  => self::prefixedMeta($dashWidgets),
            'employee_widgets'    => $employeeWidgets,
        ], 'layout.app'));
    }

    /**
     * Widgets proposés sur le tableau de bord gérant : ceux du gérant (Core puis bundles), puis les widgets employé
     * préfixés EMPLOYEE_WIDGET_PREFIX.
     *
     * @return list<string>
     */
    public static function offeredWidgetKeys(DashboardWidgets $dashWidgets): array
    {
        $employee = array_map(
            static fn(string $k): string => self::EMPLOYEE_WIDGET_PREFIX . $k,
            $dashWidgets->keys(DashboardWidget::EMPLOYEE, EmployeeController::EMPLOYEE_WIDGETS),
        );
        return array_values(array_unique(array_merge(self::defaultWidgetKeys($dashWidgets), $employee)));
    }

    /**
     * Widgets affichés tant que le gérant n'a rien choisi : ceux du gérant ; ses widgets employé s'ajoutent à la demande.
     *
     * @return list<string>
     */
    public static function defaultWidgetKeys(DashboardWidgets $dashWidgets): array
    {
        return $dashWidgets->keys(DashboardWidget::MANAGER, self::ADMIN_WIDGETS);
    }

    /**
     * Libellés, permissions et fonctionnalités des widgets de bundles du gérant, et de ceux de l'employé sous leur clé
     * préfixée.
     *
     * @return array{labels: array<string, string>, permissions: array<string, string>, features: array<string, string>}
     */
    private static function prefixedMeta(DashboardWidgets $dashWidgets): array
    {
        $meta = $dashWidgets->meta(DashboardWidget::MANAGER, self::ADMIN_WIDGETS);
        foreach ($dashWidgets->meta(DashboardWidget::EMPLOYEE, EmployeeController::EMPLOYEE_WIDGETS) as $type => $values) {
            foreach ($values as $key => $value) {
                $meta[$type][self::EMPLOYEE_WIDGET_PREFIX . $key] = $value;
            }
        }
        return $meta;
    }

    /**
     * Données des widgets employé ajoutés par le gérant à son tableau de bord (les siens : ses shifts, ses magasins),
     * calculées seulement si au moins l'un d'eux est activé.
     *
     * @param list<string> $enabledKeys Widgets activés (clés du tableau de bord gérant)
     * @return array{enabled: array<string, int>, shifts_today: array, upcoming: array, stores_map: array<int, string>, bundle: list<array{key: string, html: string}>}
     */
    private function employeeWidgets(DashboardWidgets $dashWidgets, array $user, array $enabledKeys, string $today, \Closure $userCan): array
    {
        $enabled = [];
        foreach ($enabledKeys as $key) {
            if (str_starts_with($key, self::EMPLOYEE_WIDGET_PREFIX)) {
                $enabled[substr($key, strlen(self::EMPLOYEE_WIDGET_PREFIX))] = 1;
            }
        }
        $result = ['enabled' => $enabled, 'shifts_today' => [], 'upcoming' => [], 'stores_map' => [], 'bundle' => []];
        if ($enabled === []) {
            return $result;
        }

        $userId = (int) $user['id'];
        foreach ($this->stores->findAllWithDeleted() as $s) {
            $result['stores_map'][(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }
        if (isset($enabled['shifts_today']) || isset($enabled['upcoming'])) {
            [$result['shifts_today'], $result['upcoming']] = EmployeeController::personalShifts($this->shifts->findByUser($userId), $today);
        }

        // Widgets employé des bundles : même contexte que sur le tableau de bord employé (ses propres magasins).
        $rendered = $dashWidgets->render(
            DashboardWidget::EMPLOYEE,
            EmployeeController::EMPLOYEE_WIDGETS,
            array_keys($enabled),
            [
                'user'              => $user,
                'store_ids'         => array_map(static fn($m): int => (int) $m['store_id'], $this->storeUsers->findByUser($userId)),
                'managed_store_ids' => null,
                'stores_map'        => $result['stores_map'],
                'today'             => $today,
            ],
            $this->view,
            $userCan,
            $this->storeFeatureCheck(),
        );
        foreach ($rendered as $widget) {
            $result['bundle'][] = ['key' => self::EMPLOYEE_WIDGET_PREFIX . $widget['key'], 'html' => $widget['html']];
        }
        return $result;
    }

    /** Fonctionnalité active pour les magasins de l'utilisateur (même règle que $feat dans les vues). */
    private function storeFeatureCheck(): \Closure
    {
        $storeFeatures = $this->view->get('store_features');
        return static fn(?string $f): bool => feat_bundle($f)
            && ($f === null || $storeFeatures === null || in_array($f, (array) $storeFeatures, true));
    }

    /**
     * Masse salariale globale (stores en scope) : mois en cours vs précédent,
     * + tendance mensuelle sur ~6 mois (somme de costByMonth par store géré).
     */
    private function buildFinancialOverview(array $storeIds): ?array
    {
        if ($storeIds === []) {
            return null;
        }

        $costByMonth = [];
        foreach ($storeIds as $sid) {
            // Seul le coût par mois sert ici : pas de calcul complet des statistiques sur 180 jours.
            foreach ($this->storeStats->costByMonth((int) $sid, self::FINANCIAL_TREND_PERIOD_DAYS) as $month => $cost) {
                $costByMonth[$month] = ($costByMonth[$month] ?? 0) + $cost;
            }
        }
        ksort($costByMonth);
        $trend = array_slice($costByMonth, -6, 6, true);

        $currentMonthKey = date('Y-m');
        $prevMonthKey    = date('Y-m', strtotime('-1 month'));
        $currentCost     = $costByMonth[$currentMonthKey] ?? 0.0;
        $prevCost        = $costByMonth[$prevMonthKey] ?? 0.0;

        $firstStore = $this->stores->findById((int) $storeIds[array_key_first($storeIds)]);

        return [
            'current_month'  => $currentCost,
            'previous_month' => $prevCost,
            'delta_pct'      => $prevCost > 0 ? round(($currentCost - $prevCost) / $prevCost * 100, 1) : null,
            'trend_by_month' => $trend,
            'currency'       => $firstStore['currency'] ?? 'EUR',
            'currency_style' => store_currency_style($firstStore),
        ];
    }

    /**
     * Absentéisme / RH sur la période "30 jours" (stores en scope) : taux
     * d'absentéisme moyen, congés pris/par type, retards (pointage vs shift
     * planifié — comparés par utilisateur+date, les timeclocks n'ayant pas de
     * lien direct vers un shift_id), semaines en heures supplémentaires.
     */
    private function buildHrStats(array $storeIds): ?array
    {
        if ($storeIds === []) {
            return null;
        }

        $since = date('Y-m-d', strtotime('-' . self::STATS_WIDGET_PERIOD_DAYS . ' days'));
        $today = date('Y-m-d');
        $wageCalc = new ShiftWageCalculator();

        $absRates       = [];
        $timeoffTaken   = 0;
        $timeoffByType  = [];
        $lateCount      = 0;
        $overtimeWeeks  = 0;

        foreach ($storeIds as $sid) {
            $sid = (int) $sid;
            $stats = $this->storeStats->storeStats($sid, self::STATS_WIDGET_PERIOD_DAYS);
            $absRates[] = $stats['absRate'];
            $timeoffTaken += $stats['timeoffsByStatus']['approved'] ?? 0;
            foreach ($stats['timeoffsByType'] as $type => $count) {
                $timeoffByType[$type] = ($timeoffByType[$type] ?? 0) + $count;
            }

            // Seule la période est lue (index store_id, shift_date), pas tout l'historique du store.
            $periodShifts = array_values(array_filter(
                $this->shifts->findByStoreBetween($sid, $since, $today),
                fn($s) => empty($s['deleted_at']) && $s['user_id'] !== null
                    && $s['shift_date'] >= $since && $s['shift_date'] <= $today
            ));

            $shiftStartByUserDate = [];
            foreach ($periodShifts as $s) {
                $shiftStartByUserDate[$s['user_id'] . '|' . $s['shift_date']] = $s['start_time'];
            }

            $periodTimeclocks = array_values(array_filter(
                Attendances::current()->forStoreBetween($sid, $since, $today),
                fn($tc) => ($tc['shift_date'] ?? '') >= $since && ($tc['shift_date'] ?? '') <= $today
            ));
            foreach ($periodTimeclocks as $tc) {
                $key = ($tc['user_id'] ?? '') . '|' . ($tc['shift_date'] ?? '');
                if (!isset($shiftStartByUserDate[$key])) {
                    continue;
                }
                $expected = strtotime($tc['shift_date'] . ' ' . $shiftStartByUserDate[$key]);
                $actual   = strtotime((string) ($tc['clock_in_time'] ?? ''));
                if ($expected === false || $actual === false) {
                    continue;
                }
                if (($actual - $expected) / 60 > self::HR_LATE_THRESHOLD_MINUTES) {
                    $lateCount++;
                }
            }

            $storeTypesMap = array_column($this->shiftTypes->findByStore($sid), null, 'id');
            $hoursByUserWeek = [];
            foreach ($periodShifts as $s) {
                $wage = $wageCalc->costOf($s, $storeTypesMap);
                $week = date('o-\WW', strtotime($s['shift_date']));
                $key  = $s['user_id'] . '|' . $week;
                $hoursByUserWeek[$key] = ($hoursByUserWeek[$key] ?? 0) + $wage['net_minutes'] / 60;
            }
            foreach ($hoursByUserWeek as $hours) {
                if ($hours > self::HR_OVERTIME_WEEKLY_HOURS) {
                    $overtimeWeeks++;
                }
            }
        }

        arsort($timeoffByType);

        return [
            'abs_rate'        => $absRates !== [] ? round(array_sum($absRates) / count($absRates), 2) : 0.0,
            'timeoff_taken'   => $timeoffTaken,
            'timeoff_by_type' => $timeoffByType,
            'late_count'      => $lateCount,
            'overtime_weeks'  => $overtimeWeeks,
            'period_days'     => self::STATS_WIDGET_PERIOD_DAYS,
        ];
    }
}
