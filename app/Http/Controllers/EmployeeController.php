<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use kintai\Contract\Extension\DashboardWidget;
use App\Extension\DashboardStats;
use App\Extension\DashboardLayout;
use App\Extension\DashboardWidgets;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserDashboardPref;
use App\Models\UserNavPref;
use App\Models\UserShiftTypeRate;
use App\Auth\PermissionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\DashboardLayoutResolver;
use App\Services\NotificationService;
use App\Services\SchedulePublication;
use App\Http\Controllers\HasBaseUrl;
use App\Http\Controllers\HasStoreFeatureCheck;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class EmployeeController
{
    use HasBaseUrl;
    use HasStoreFeatureCheck;
    public const EMPLOYEE_WIDGETS = [
        'shifts_today',
        'upcoming',
        'monthly_stats',
    ];

    public function __construct(
        private readonly ViewFactory $view,
        private readonly StoreRepositoryInterface $stores,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifs,
        private readonly PermissionService $permissions,
        // Disposition de l'utilisateur, sinon défaut de son rôle ; mises en page nommées (null : disposition enregistrée seule).
        private readonly ?DashboardLayoutResolver $layoutResolver = null,
    ) {}

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function dashboard(Request $request): Response
    {
        $user   = $request->attributes->get('auth_user');
        $userId = (int) ($user['id'] ?? 0);
        $today  = date('Y-m-d');

        // Brouillons écartés : l'employé ne voit que le planning publié (voir SchedulePublication).
        [$shiftsToday, $upcomingShifts] = self::personalShifts(SchedulePublication::staffView(Shift::findByUser($userId)), $today);

        $storesMap   = [];
        foreach ($this->stores->findAllWithDeleted() as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }

        // Widgets proposés : ceux du Core et ceux des bundles actifs (Bundle::dashboardWidgets(), contrat 1.4). Les
        // widgets historiques d'un bundle qui déclare les siens ne sont plus rendus par le Core ($enabledWidgets).
        $dashWidgets = DashboardWidgets::current();
        $widgetKeys  = $dashWidgets->keys(DashboardWidget::EMPLOYEE, self::EMPLOYEE_WIDGETS);
        $sizes       = $dashWidgets->sizes(DashboardWidget::EMPLOYEE, self::EMPLOYEE_WIDGETS);
        $saved       = UserDashboardPref::getEnabledWidgets($userId, 'employee');
        $enabledAll  = array_flip(array_values(
            $saved !== null
                ? array_intersect($saved, $widgetKeys)
                : $widgetKeys
        ));
        $enabledWidgets = $enabledAll;

        $userCan = fn(string $perm): bool => $this->permissions->can($user, $perm, null);
        $storeFeatures = $this->view->shared('store_features');
        $bundleWidgets = $dashWidgets->render(
            DashboardWidget::EMPLOYEE,
            self::EMPLOYEE_WIDGETS,
            array_keys($enabledAll),
            [
                'user'              => $user,
                'store_ids'         => array_map(fn($m) => (int) $m['store_id'], StoreUser::findByUser($userId)),
                'managed_store_ids' => null,
                'stores_map'        => $storesMap,
                'today'             => $today,
            ],
            $this->view,
            $userCan,
            static fn(?string $f): bool => feat_bundle($f)
                && ($f === null || $storeFeatures === null || in_array($f, (array) $storeFeatures, true)),
        );

        // Cartes de statistiques des bundles (contrat 1.19).
        $bundleStats = DashboardStats::current()->cards(
            ['user' => $user, 'store_ids' => array_map(fn($m) => (int) $m['store_id'], StoreUser::findByUser($userId)), 'stores_map' => $storesMap, 'today' => $today],
            static fn(?string $f): bool => feat_bundle($f) && ($f === null || $storeFeatures === null || in_array($f, (array) $storeFeatures, true)),
        );

        return response($this->view->make('employee.dashboard', [
            'title'            => __('dashboard'),
            'user'             => $user,
            'shifts_today'     => $shiftsToday,
            'upcoming'         => $upcomingShifts,
            'stores_map'       => $storesMap,
            'user_can'         => $userCan,
            'enabled_widgets'  => $enabledWidgets,
            'enabled_widgets_all' => $enabledAll,
            'all_widgets'      => $widgetKeys,
            'widget_layout'    => DashboardLayout::resolve($widgetKeys, $this->layoutResolver?->layoutFor($userId, 'employee') ?? UserDashboardPref::getLayout($userId, 'employee'), $sizes['widths'], $sizes['heights']),
            'dashboard_layouts' => $this->layoutResolver?->namedLayouts($userId, 'employee') ?? [],
            'dashboard_layouts_route' => 'employee.dashboard.layouts',
            'dashboard_roles'  => !empty($user['is_admin']) ? ($this->layoutResolver?->roleOptions('employee') ?? []) : [],
            'widget_limits'    => $sizes['limits'],
            'widget_refresh'   => $sizes['refresh'],
            'bundle_widgets'   => $bundleWidgets,
            'bundle_stats'     => $bundleStats,
            'bundle_widget_meta' => $dashWidgets->meta(DashboardWidget::EMPLOYEE, self::EMPLOYEE_WIDGETS),
        ])->render());
    }

    /**
     * Shifts d'un utilisateur pour ses widgets personnels (« Mes shifts du jour », « Mes prochains shifts ») : ceux du
     * jour, et les 5 suivants par date. Partagé avec le tableau de bord gérant (widgets « emp_ »).
     *
     * @param list<array<string, mixed>> $myShifts
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function personalShifts(array $myShifts, string $today): array
    {
        $shiftsToday = array_values(array_filter($myShifts, fn($s) => ($s['shift_date'] ?? '') === $today));
        $upcoming = array_values(array_filter($myShifts, fn($s) => ($s['shift_date'] ?? '') > $today));
        usort($upcoming, fn($a, $b) => strcmp($a['shift_date'] ?? '', $b['shift_date'] ?? ''));
        return [$shiftsToday, array_slice($upcoming, 0, 5)];
    }

    // -------------------------------------------------------------------------
    // Timeline hebdomadaire
    // -------------------------------------------------------------------------

    public function shifts(Request $request): Response
    {
        // Redirige vers la vue Timeline (3 jours par défaut à partir d'aujourd'hui)
        return redirect()->away($this->base() . '/employee/shifts/day?start=' . date('Y-m-d') . '&view=3days');
    }

    public function shiftsCalendar(Request $request): Response
    {
        $user   = $request->attributes->get('auth_user');
        $userId = (int) ($user['id'] ?? 0);

        // Mois affiché
        $monthParam = (string) ($request->query('month') ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $monthParam = date('Y-m');
        }
        [$year, $month] = array_map('intval', explode('-', $monthParam));
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $prevMonth  = date('Y-m', strtotime($monthStart . ' -1 month'));
        $nextMonth  = date('Y-m', strtotime($monthStart . ' +1 month'));
        $monthLabel = month_label($monthStart);

        // Collègues du même store
        $memberships = StoreUser::findByUser($userId);
        $myStoreIds  = array_unique(array_map(fn($m) => (int) $m['store_id'], $memberships));

        // Jour de début de semaine (premier store de l'employé)
        $empFirstStore = !empty($myStoreIds) ? $this->stores->findById($myStoreIds[0]) : null;
        $weekStartDay  = max(1, min(7, (int) ($empFirstStore['week_start_day'] ?? 1)));

        $colleagueIds = [];
        foreach ($myStoreIds as $sid) {
            foreach (StoreUser::findByStore($sid) as $m) {
                $mid = (int) $m['user_id'];
                if ($mid !== $userId) $colleagueIds[$mid] = true;
            }
        }

        // Shifts du mois (employé + collègues pour contexte)
        $allUserIds   = array_merge([$userId], array_keys($colleagueIds));
        $shiftsByDate = [];
        foreach ($allUserIds as $uid) {
            foreach (SchedulePublication::staffView(Shift::findByUser($uid)) as $s) {
                $d = $s['shift_date'] ?? '';
                if ($d >= $monthStart && $d <= $monthEnd) {
                    $shiftsByDate[$d][] = $s;
                }
            }
        }

        // Map couleurs (la couleur de l'employé courant)
        $usersColour = [];
        foreach ($this->users->findAll() as $u) {
            $usersColour[(int) $u['id']] = $u['color'] ?? '#6366f1';
        }

        $typesMap = [];
        foreach (ShiftType::findAll() as $t) {
            $typesMap[(int) $t['id']] = $t;
        }
        $storesMap = [];
        foreach ($this->stores->findAllWithDeleted() as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? '';
        }


        $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base      = $this->base();
        $icalLinks = [];
        foreach ($myStoreIds as $sid) {
            $tokenRow = IcalToken::findOrCreateForUserAndStore($userId, $sid);
            if ($tokenRow) {
                $icalLinks[$sid] = "{$scheme}://{$host}{$base}/ical/{$tokenRow['token']}/{$sid}/shifts.ics";
            }
        }

        return response($this->view->make('scheduling.shifts-calendar', [
            'title'          => __('my_planning'),
            'year'           => $year,
            'month'          => $month,
            'month_label'    => $monthLabel,
            'month_start'    => $monthStart,
            'month_end'      => $monthEnd,
            'prev_month'     => $prevMonth,
            'next_month'     => $nextMonth,
            'today'          => date('Y-m-d'),
            'shifts_by_date' => $shiftsByDate,
            'my_user_id'     => $userId,
            'users_colour'   => $usersColour,
            'types_map'      => $typesMap,
            'stores_map'     => $storesMap,
            'week_start_day' => $weekStartDay,
            'ical_links'     => $icalLinks,
            'is_manager_view'=> false,
        ])->render());
    }

    public function shiftsWeek(Request $request): Response
    {
        $user   = $request->attributes->get('auth_user');
        $userId = (int) ($user['id'] ?? 0);

        $weekParam = $request->query('week', '');
        if ($weekParam && preg_match('/^\d{4}-\d{1,2}$/', (string) $weekParam)) {
            [$y, $w] = explode('-', (string) $weekParam);
            $monday = (new \DateTimeImmutable())->setISODate((int) $y, (int) $w, 1);
        } else {
            $now    = new \DateTimeImmutable();
            $monday = $now->setISODate((int) $now->format('o'), (int) $now->format('W'), 1);
        }

        $sunday   = $monday->modify('+6 days');
        $prevWeek = $monday->modify('-7 days')->format('o-W');
        $nextWeek = $monday->modify('+7 days')->format('o-W');

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $monday->modify("+{$i} days");
        }

        $weekStart = $monday->format('Y-m-d');
        $weekEnd   = $sunday->format('Y-m-d');

        // Collègues du même store
        $memberships = StoreUser::findByUser($userId);
        $myStoreIds  = array_unique(array_map(fn($m) => (int) $m['store_id'], $memberships));

        $colleagueIds = [];
        foreach ($myStoreIds as $sid) {
            foreach (StoreUser::findByStore($sid) as $m) {
                $mid = (int) $m['user_id'];
                if ($mid !== $userId) {
                    $colleagueIds[$mid] = true;
                }
            }
        }

        // Charger tous les shifts de la semaine (moi + collègues)
        $allUserIds   = array_merge([$userId], array_keys($colleagueIds));
        $shiftsByDate = [];
        foreach ($allUserIds as $uid) {
            foreach (SchedulePublication::staffView(Shift::findByUser($uid)) as $s) {
                $d = $s['shift_date'] ?? '';
                if ($d >= $weekStart && $d <= $weekEnd) {
                    $shiftsByDate[$d][] = $s;
                }
            }
        }

        // Construire la map des noms pour l'affichage
        $usersMap = [];
        foreach ($this->users->findAll() as $u) {
            $name = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
            $usersMap[(int) $u['id']] = $name ?: ($u['email'] ?? '#' . $u['id']);
        }

        $typesMap  = [];
        foreach (ShiftType::findAll() as $t) {
            $typesMap[(int) $t['id']] = $t;
        }
        $storesMap = [];
        foreach ($this->stores->findAllWithDeleted() as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? '#' . $s['id'];
        }


        return response($this->view->make('scheduling.shifts', [
            'title'          => 'Mon planning',
            'days'           => $days,
            'shifts_by_date' => $shiftsByDate,
            'types_map'      => $typesMap,
            'stores_map'     => $storesMap,
            'users_map'      => $usersMap,
            'my_user_id'     => $userId,
            'prev_week'      => $prevWeek,
            'next_week'      => $nextWeek,
            'week_label'     => 'Semaine ' . $monday->format('W') . ' · ' . $monday->format('d M') . ' – ' . $sunday->format('d M Y'),
            'today'          => date('Y-m-d'),
        ])->render());
    }

    // -------------------------------------------------------------------------
    // Timeline jour (Gantt)
    // -------------------------------------------------------------------------

    public function shiftDay(Request $request): Response
    {
        if (($resp = $this->assertFeature($request, 'shifts')) !== null) {
            return $resp;
        }
        $user   = $request->attributes->get('auth_user');
        $userId = (int) ($user['id'] ?? 0);

        // Mode d'affichage : '3days' (défaut) ou 'week'
        $viewMode = $request->query('view', '3days');
        if (!in_array($viewMode, ['3days', 'week'], true)) {
            $viewMode = '3days';
        }

        // Jour de départ
        $startParam = (string) $request->query('start', date('Y-m-d'));
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)
            ? new \DateTimeImmutable($startParam)
            : new \DateTimeImmutable();

        // Calcul de la plage de jours
        if ($viewMode === 'week') {
            // Lundi de la semaine ISO contenant $anchor
            $firstDay = $anchor->setISODate((int) $anchor->format('o'), (int) $anchor->format('W'), 1);
            $numDays  = 7;
        } else {
            $firstDay = $anchor;
            $numDays  = 3;
        }

        $days = [];
        for ($i = 0; $i < $numDays; $i++) {
            $days[] = $firstDay->modify("+{$i} days");
        }

        $rangeStart = $days[0]->format('Y-m-d');
        $rangeEnd   = $days[$numDays - 1]->format('Y-m-d');
        $prevStart  = $firstDay->modify("-{$numDays} days")->format('Y-m-d');
        $nextStart  = $firstDay->modify("+{$numDays} days")->format('Y-m-d');

        // Mes stores
        $memberships = StoreUser::findByUser($userId);
        $myStoreIds  = array_values(array_unique(array_map(fn($m) => (int) $m['store_id'], $memberships)));

        $storesList = [];
        $storesMap  = [];
        foreach ($myStoreIds as $sid) {
            $st = $this->stores->findById($sid);
            if ($st !== null) {
                $storesList[]    = $st;
                $storesMap[$sid] = $st['name'] ?? ('#' . $sid);
            }
        }

        // Store affiché (par défaut : premier store de l'employé)
        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        if (!in_array($filterStoreId, $myStoreIds, true)) {
            $filterStoreId = $myStoreIds[0] ?? 0;
        }

        // Peut gérer les shifts du store affiché (Owner, ou RBAC accordant shifts.update sur ce store)
        $canManage = !empty($user['is_admin'])
            || $this->permissions->can($user, 'shifts.update', $filterStoreId > 0 ? $filterStoreId : null);

        $usersMap      = [];
        $userColorMap  = [];
        foreach ($this->users->findAll() as $u) {
            $name = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
            $usersMap[(int) $u['id']]     = $name ?: ($u['email'] ?? '#' . $u['id']);
            $userColorMap[(int) $u['id']] = $u['color'] ?? '#6366f1';
        }

        // Membres du store affiché (triés par nom)
        $memberIds = [];
        if ($filterStoreId > 0) {
            foreach (StoreUser::findByStore($filterStoreId) as $m) {
                $memberIds[] = (int) $m['user_id'];
            }
        }
        usort($memberIds, fn($a, $b) => strcmp($usersMap[$a] ?? '', $usersMap[$b] ?? ''));

        $typesMap     = [];
        $typeStoreIds = [];
        foreach (ShiftType::findAll() as $t) {
            $tid = (int) $t['id'];
            $typesMap[$tid]     = $t;
            $typeStoreIds[$tid] = ShiftType::getStoreIds($tid);
        }

        // Shifts de la plage (store affiché uniquement), groupés par date puis user
        $shiftsByDateUser = []; // date → uid → shift[]
        if ($filterStoreId > 0) {
            // Brouillons visibles seulement de qui gère le planning du magasin.
            $storeShifts = Shift::findByStore($filterStoreId);
            foreach ($canManage ? $storeShifts : SchedulePublication::staffView($storeShifts) as $s) {
                $d = $s['shift_date'] ?? '';
                if ($d < $rangeStart || $d > $rangeEnd) continue;
                $uid = (int) ($s['user_id'] ?? 0);
                $shiftsByDateUser[$d][$uid][] = $s;
            }
        }

        // « Lu » automatique : les notifications des shifts de l'utilisateur affichés dans cette plage.
        $ownShiftIds = [];
        foreach ($shiftsByDateUser as $byUser) {
            foreach ($byUser[$userId] ?? [] as $own) {
                $ownShiftIds[] = (int) $own['id'];
            }
        }
        if ($ownShiftIds !== []) {
            $this->notifs->markReadFor($userId, 'shift', $ownShiftIds);
        }
        // Un shift supprimé n'est plus affiché : sa notification désigne le jour, lu dès que ce jour est à l'écran.
        $this->notifs->markReadFor($userId, 'shift_day', array_map(
            static fn(\DateTimeInterface $d) => shift_day_reference($d->format('Y-m-d')),
            $days
        ));

        // Taux horaires et devise (store affiché)
        $ratesMap    = []; // uid → shift_type_id → hourly_rate
        $currencyMap = []; // uid → currency string
        $storeObj    = $filterStoreId > 0 ? $this->stores->findById($filterStoreId) : null;
        $currency      = strtoupper(trim($storeObj['currency'] ?? 'JPY'));
        $currencyStyle = store_currency_style($storeObj);
        foreach ($memberIds as $uid) {
            $currencyMap[$uid] = $currency;
            foreach (UserShiftTypeRate::findByUser($uid) as $r) {
                $ratesMap[$uid][(int) $r['shift_type_id']] = (float) $r['hourly_rate'];
            }
        }

        // Paramètres de planification du store (pour les alertes timeline, admin/manager uniquement)
        $storeSettings = [
            'min_staff_per_day'    => (int) ($storeObj['min_staff_per_day']    ?? 0),
            'min_shift_minutes'    => (int) ($storeObj['min_shift_minutes']    ?? 0),
            'max_shift_minutes'    => (int) ($storeObj['max_shift_minutes']    ?? 0),
            'low_staff_hour_start' => (int) ($storeObj['low_staff_hour_start'] ?? -1),
            'low_staff_hour_end'   => (int) ($storeObj['low_staff_hour_end']   ?? -1),
        ];


        return response($this->view->make('scheduling.shifts-timeline', [
            'title'               => 'Mon planning',
            'page_heading'        => __('my_planning'),
            'show_request_swap'   => true,
            'days'                => $days,
            'period_mode'         => $viewMode,
            'prev_start'          => $prevStart,
            'next_start'          => $nextStart,
            'shifts_by_date_user' => $shiftsByDateUser,
            'all_user_ids'        => $memberIds,
            'users_map'           => $usersMap,
            'user_color_map'      => $userColorMap,
            'types_map'           => $typesMap,
            'type_store_ids'      => $typeStoreIds,
            'my_user_id'          => $userId,
            'highlight_id'        => (int) $request->query('highlight', 0),
            'today'               => date('Y-m-d'),
            'can_manage'          => $canManage,
            'rates_map'           => $ratesMap,
            'currency_map'        => $currencyMap,
            'currency_symbol_style' => $currencyStyle,
            'filter_store_id'     => $filterStoreId,
            'stores_map'          => $storesMap,
            'available_stores'    => $storesList,
            'store_settings'      => $storeSettings,
        ])->render());
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Profil — géré désormais par AuthController (/profile), une seule page
    // partagée admin/manager/employé. Cette route ne sert plus qu'à rediriger
    // les anciens liens/favoris vers ce même endroit.
    // -------------------------------------------------------------------------

    public function profile(Request $request): Response
    {
        $qs = [];
        if ($request->query('tab') !== null) {
            $qs['tab'] = $request->query('tab');
        }
        if ($request->query('store_id') !== null) {
            $qs['store_id'] = $request->query('store_id');
        }
        $query = $qs ? ('?' . http_build_query($qs)) : '';
        return redirect()->away($this->base() . '/profile' . $query);
    }

    public function saveProfileAvailability(Request $request): Response
    {
        $user    = $request->attributes->get('auth_user');
        $userId  = (int) ($user['id'] ?? 0);
        $body    = $request->request->all();
        $storeId = (int) ($body['store_id'] ?? 0);

        $memberships = StoreUser::findByUser($userId);
        $myStoreIds  = array_map(fn($m) => (int) $m['store_id'], $memberships);
        if (!in_array($storeId, $myStoreIds, true)) {
            return redirect()->away($this->base() . '/profile?tab=availability')->with('error', 'forbidden');
        }

        $oldAvailabilities = Availability::findByUserAndStore($userId, $storeId);
        Availability::deleteByUserAndStore($userId, $storeId);

        $days = $body['days'] ?? [];
        foreach (range(1, 7) as $dow) {
            $dayData = $days[$dow] ?? [];
            $isAvail = isset($dayData['available']) ? 1 : 0;
            if ($isAvail && !empty($dayData['start_time']) && !empty($dayData['end_time'])) {
                Availability::put([
                    'store_id'     => $storeId,
                    'user_id'      => $userId,
                    'day_of_week'  => $dow,
                    'start_time'   => substr($dayData['start_time'], 0, 5),
                    'end_time'     => substr($dayData['end_time'], 0, 5),
                    'is_available' => 1,
                ]);
            } else {
                Availability::put([
                    'store_id'     => $storeId,
                    'user_id'      => $userId,
                    'day_of_week'  => $dow,
                    'start_time'   => '00:00',
                    'end_time'     => '23:59',
                    'is_available' => 0,
                ]);
            }
        }

        $newAvailabilities = Availability::findByUserAndStore($userId, $storeId);
        $this->auditLogger->logUpdate($request, 'availability.updated', 'availability', null, $oldAvailabilities, $newAvailabilities, ['store_id' => $storeId], $storeId);

        return redirect()->away($this->base() . '/profile?tab=availability&store_id=' . $storeId)->with('success', 'saved');
    }

    // Bourse aux shifts : bundle distribué hors monorepo (kintai-bundle-shift-claim), voir docs/architecture.md

    // -------------------------------------------------------------------------
    // Paramètres du menu de navigation (employé)
    // -------------------------------------------------------------------------

    private const NAV_EMPLOYEE_SECTIONS = ['planning', 'requests', 'statistics', 'account'];

    // Items disponibles pour la barre de navigation inférieure mobile (hors "home" toujours présent)

    /**
     * L'employé gère son menu depuis l'onglet "nav" de son profil (une seule UI,
     * plus de page séparée) — cette route ne sert plus qu'à rediriger les anciens
     * liens/favoris vers ce même endroit.
     */
    public function navSettings(Request $request): Response
    {
        return redirect()->away($this->base() . '/profile?tab=nav');
    }

    public function saveNavSettings(Request $request): Response
    {
        $user    = $request->attributes->get('auth_user');
        $userId  = (int) $user['id'];
        $oldHidden = UserNavPref::getHiddenRow($userId);
        $oldSectionOrder = UserNavPref::getSectionOrder($userId);
        $oldBottomNav = UserNavPref::getBottomNavItems($userId);

        // Seules les clés réellement proposées dans le formulaire (filtrées par store_features
        // côté vue) doivent voir leur état modifié ; les autres gardent leur préférence existante,
        // sinon une feature désactivée au moment de la sauvegarde serait masquée définitivement.
        $available   = array_values((array) $request->post('nav_available', []));
        $visible     = array_keys(array_filter((array) $request->post('nav', [])));
        $newlyHidden = array_diff($available, $visible);
        $keptHidden  = array_diff($oldHidden, $available);
        $hidden      = array_values(array_unique(array_merge($newlyHidden, $keptHidden)));
        UserNavPref::saveHidden($userId, $hidden);

        if ($request->post('reset_section_order')) {
            UserNavPref::saveSectionOrder($userId, []);
        } else {
            $rawOrder     = array_values((array) $request->post('section_order', []));
            $sectionOrder = array_values(array_intersect($rawOrder, self::NAV_EMPLOYEE_SECTIONS));
            if (!empty($sectionOrder)) {
                UserNavPref::saveSectionOrder($userId, $sectionOrder);
            }
        }

        $rawBottom   = array_values((array) $request->post('bottom_nav', []));
        $bottomItems = array_values(array_intersect($rawBottom, \App\Extension\Navigation::current()->mobileKeys(false)));
        $count       = count($bottomItems);
        if ($count >= 2 && $count <= 4) {
            UserNavPref::saveBottomNavItems($userId, $bottomItems);
        }

        $this->auditLogger->logUpdate($request, 'nav_settings.updated', 'user_nav_prefs', $userId, [
            'hidden' => $oldHidden,
            'section_order' => $oldSectionOrder,
            'bottom_nav' => $oldBottomNav,
        ], [
            'hidden' => $hidden,
            'section_order' => $sectionOrder ?? [],
            'bottom_nav' => $bottomItems,
        ], []);

        return redirect()->away($this->base() . '/profile?tab=nav')->with('success', '1');
    }
}
