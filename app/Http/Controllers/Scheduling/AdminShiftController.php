<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Services\NotificationService;
use App\Services\SchedulePublication;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\ShiftWeekTemplate;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use kintai\Contract\Event\OpenShiftClosed;
use App\Extension\Absences;
use App\Extension\Events;
use App\Extension\ShiftFormSections;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Auth\PermissionService;
use App\Services\AuditLogger;
use App\Services\AvailabilityGuard;
use Illuminate\Support\Facades\Log;
use App\Services\ShiftWageCalculator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use App\Http\Controllers\HasAdminAccess;
use App\Services\ShiftServiceInterface;

final class AdminShiftController
{
    use HasAdminAccess;

    public function __construct(
        private readonly ViewFactory $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifs,
        private readonly ShiftServiceInterface $shiftService,
        private readonly PermissionService $permissions,
        private readonly AvailabilityGuard $availabilityGuard,
        // Publication du planning par magasin ; null (tests, appel direct) : tout est publié, comme avant.
        private readonly ?SchedulePublication $publication = null,
        // Modèles de semaine proposés sur la timeline ; null : bloc des modèles masqué.
    ) {}

    /**
     * Écarte les brouillons des magasins dont l'utilisateur ne gère pas le planning (shifts.view sans shifts.update) :
     * les vues d'administration montrent alors ce que voient les employés.
     *
     * @param list<array<string, mixed>> $shifts
     * @return list<array<string, mixed>>
     */
    private function withoutForeignDrafts(Request $request, array $shifts): array
    {
        if ($this->publication === null) {
            return $shifts;
        }
        $user = $request->attributes->get('auth_user') ?? [];
        if (!empty($user['is_admin'])) {
            return $shifts;
        }
        $canManage = [];
        return array_values(array_filter($shifts, function (array $s) use ($user, &$canManage): bool {
            if ($this->publication->isPublished($s)) {
                return true;
            }
            $sid = (int) ($s['store_id'] ?? 0);
            return $canManage[$sid] ??= $this->permissions->can($user, 'shifts.update', $sid);
        }));
    }

    /** Le jour est-il visible des employés du magasin ? Un brouillon ne leur envoie aucune notification. */
    private function isPublishedDay(int $storeId, string $date): bool
    {
        return $this->publication?->isDatePublished($storeId, $date) ?? true;
    }

    /** @return array<int, array> id => type, pour les stores gérés (ou tous si Owner). */
    private function shiftTypesForManaged(?array $managedIds): array
    {
        return $managedIds === null
            ? ShiftType::findAll()
            : ShiftType::findByStores($managedIds);
    }

    /**
     * Champ d'ajustement manuel (taux horaire ou minutes actives) : vide = null
     * (calcul automatique), sinon la valeur saisie prime sur la résolution
     * habituelle de ShiftWageCalculator::costOf().
     */
    private function postedOverride(Request $request, string $field): int|float|null
    {
        $raw = trim($request->post($field, ''));
        if ($raw === '') {
            return null;
        }
        return $field === 'net_minutes_override' ? (int) $raw : (float) $raw;
    }

    /**
     * Vrai si l'utilisateur a un congé approuvé couvrant la date de shift donnée.
     */
    private function hasApprovedTimeoffConflict(int $userId, string $shiftDate): bool
    {
        if ($userId <= 0 || $shiftDate === '') {
            return false;
        }
        // Absences lues à travers les fournisseurs (Bundle::absenceProviders()) ; aucune sans bundle timeoff.
        foreach (Absences::current()->forUser($userId) as $req) {
            if (($req['status'] ?? '') !== 'approved') continue;
            if (($req['start_date'] ?? '') <= $shiftDate && $shiftDate <= ($req['end_date'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Données de l'encadré « employé non disponible » si l'employé s'est déclaré indisponible ce jour-là, sinon null.
     * Un avertissement, jamais un blocage : le manager peut forcer l'horaire (force_availability).
     *
     * @return array{user_name: string, weekday: string, date: string}|null
     */
    private function availabilityConflict(int $userId, int $storeId, string $shiftDate): ?array
    {
        $weekday = $this->availabilityGuard->unavailableWeekday($userId, $storeId, $shiftDate);
        if ($weekday === null) {
            return null;
        }

        $user = $this->users->findById($userId) ?? [];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        return [
            'user_name' => (string) ($user['display_name'] ?? $user['email'] ?? ('#' . $userId)),
            'weekday'   => __($days[$weekday - 1]),
            'date'      => $shiftDate,
        ];
    }

    /** Phrase de l'avertissement (formulaire et réponses JSON). */
    private function availabilityMessage(array $conflict): string
    {
        return __('shift_availability_conflict', ['name' => $conflict['user_name'], 'weekday' => $conflict['weekday'], 'date' => $conflict['date']]);
    }

    /** Vrai si le manager a choisi de forcer l'horaire malgré l'indisponibilité de l'employé. */
    private function availabilityForced(Request $request): bool
    {
        return $request->post('force_availability', '') === '1';
    }

    /**
     * Réaffiche le formulaire de shift, valeurs saisies conservées, avec l'encadré rouge « employé non disponible » et
     * ses deux boutons (Annuler, Forcer l'horaire).
     *
     * @param array{user_name: string, weekday: string, date: string} $conflict
     */
    private function shiftFormWithAvailabilityConflict(Request $request, string $mode, array $shift, array $conflict): Response
    {
        $managedIds = $this->managedIds($request);
        $allUsers   = $managedIds !== null
            ? array_values(array_filter($this->users->findAll(), fn($u) => in_array((int) $u['id'], $this->memberUserIds($managedIds), true)))
            : $this->users->findAll();

        return response($this->view->make('scheduling.shifts-form', [
            'title'                 => $mode === 'edit' ? 'Modifier le shift #' . $shift['id'] : 'Nouveau shift',
            'mode'                  => $mode,
            'shift'                 => $shift,
            'all_users'             => $allUsers,
            'all_stores'            => $this->availableStores($managedIds),
            'all_types'             => $this->shiftTypesForManaged($managedIds),
            'redirect_to'           => safe_redirect_path((string) $request->post('redirect_to', ''), ''),
            'availability_conflict' => $conflict,
            'availability_message'  => $this->availabilityMessage($conflict),
        ])->render());
    }

    /** Champs du formulaire de shift tels que postés, pour le réafficher à l'identique. */
    private function postedShiftFields(Request $request): array
    {
        $fields = [];
        foreach (['store_id', 'user_id', 'shift_date', 'start_time', 'end_time', 'pause_minutes', 'notes', 'is_open', 'open_shift_note', 'hourly_rate_override', 'net_minutes_override'] as $name) {
            if ($request->hasPost($name)) {
                $fields[$name] = $request->post($name, '');
            }
        }
        return $fields;
    }

    /**
     * Valeurs de remplacement (:date/:start/:end/:store) pour les notifications
     * shift_assigned/shift_updated/shift_deleted — une notification "vous avez un
     * nouveau shift" sans indiquer lequel obligeait l'employé à aller vérifier son
     * planning pour comprendre de quoi il s'agit.
     */
    private function shiftNotificationReplace(string $shiftDate, string $startTime, string $endTime, int $storeId): array
    {
        $store = $this->stores->findById($storeId);
        return [
            'date'  => $shiftDate,
            'start' => substr($startTime, 0, 5),
            'end'   => substr($endTime, 0, 5),
            'store' => $store['name'] ?? '',
        ];
    }

    /**
     * Lien de la notification vers la journée concernée du planning personnel de
     * l'employé — permet de cliquer la notification pour aller directement voir le
     * shift, au lieu de devoir le retrouver soi-même dans son planning.
     */
    private function shiftNotificationLink(string $shiftDate, int $shiftId = 0): string
    {
        // highlight : le shift concerné est mis en évidence dans la vue jour (absent pour un shift supprimé).
        return '/employee/shifts/day?start=' . $shiftDate . ($shiftId > 0 ? '&highlight=' . $shiftId : '');
    }

    /**
     * Type de shift dominant (le plus de minutes) calculé automatiquement depuis
     * le chevauchement horaire entre start_time/end_time et les types actifs du
     * store — remplace l'ancien choix manuel unique. Utilisé uniquement pour
     * l'affichage (couleur/étiquette) ; la facturation réelle est recalculée par
     * tranche à la lecture des rapports via ShiftWageCalculator::costOf().
     */
    private function dominantShiftTypeId(int $storeId, string $startTime, string $endTime, int $netMinutes): ?int
    {
        $result = (new ShiftWageCalculator())->calculate(
            $startTime,
            $endTime,
            ShiftType::findActive($storeId),
            $netMinutes
        );
        return $result['dominant_type_id'];
    }

    public function shifts(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $usersMap   = $this->shiftService->getUsersMap();
        $typesMap   = $this->shiftService->getShiftTypesMap();

        // Filtre mois (défaut = mois en cours)
        $filterMonth = $request->query('month') ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
            $filterMonth = date('Y-m');
        }

        // Filtre store et personnel
        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        $filterUserId  = (int) ($request->query('user_id')  ?? 0);

        // Get filtered shifts from service
        $allShifts = $this->shiftService->getShiftsForAdmin(
            $managedIds ?? array_map(fn($s) => (int)$s['id'], $this->stores->findAll()),
            $filterMonth,
            $filterStoreId,
            $filterUserId
        );
        $allShifts = $this->withoutForeignDrafts($request, $allShifts);

        // Tri (Keep it here for now as it depends on usersMap/typesMap)
        $sort = $request->query('sort') ?? 'date_asc';
        usort($allShifts, function ($a, $b) use ($sort, $usersMap, $typesMap) {
            $nameA  = $usersMap[(int) ($a['user_id'] ?? 0)] ?? '';
            $nameB  = $usersMap[(int) ($b['user_id'] ?? 0)] ?? '';
            $dateA  = ($a['shift_date'] ?? '') . ($a['start_time'] ?? '');
            $dateB  = ($b['shift_date'] ?? '') . ($b['start_time'] ?? '');
            $typeA  = strtolower($typesMap[(int) ($a['shift_type_id'] ?? 0)]['name'] ?? '');
            $typeB  = strtolower($typesMap[(int) ($b['shift_type_id'] ?? 0)]['name'] ?? '');
            $netA   = (int) ($a['duration_minutes'] ?? 0) - (int) ($a['pause_minutes'] ?? 0);
            $netB   = (int) ($b['duration_minutes'] ?? 0) - (int) ($b['pause_minutes'] ?? 0);
            return match ($sort) {
                'staff_asc'     => strcmp($nameA, $nameB)  ?: strcmp($dateA, $dateB),
                'staff_desc'    => strcmp($nameB, $nameA)  ?: strcmp($dateA, $dateB),
                'type_asc'      => strcmp($typeA, $typeB)  ?: strcmp($dateA, $dateB),
                'type_desc'     => strcmp($typeB, $typeA)  ?: strcmp($dateA, $dateB),
                'duration_asc'  => ($netA <=> $netB)       ?: strcmp($dateA, $dateB),
                'duration_desc' => ($netB <=> $netA)       ?: strcmp($dateA, $dateB),
                'date_asc'      => strcmp($dateA, $dateB),
                default         => strcmp($dateB, $dateA), // date_desc
            };
        });

        // Pour le filtre UI, ne proposer que les stores accessibles
        $storesMap = $this->buildStoresMap($managedIds);

        // Liste du personnel accessible pour le filtre
        $memberIds = $managedIds !== null ? $this->memberUserIds($managedIds) : null;
        $usersForFilter = [];
        foreach ($usersMap as $uid => $name) {
            if ($memberIds === null || in_array($uid, $memberIds, true)) {
                $usersForFilter[$uid] = $name;
            }
        }
        asort($usersForFilter);

        return response($this->view->make('scheduling.shifts', [
            'title'            => 'Shifts',
            'shifts'           => $allShifts,
            'users_map'        => $usersMap,
            'stores_map'       => $storesMap,
            'types_map'        => $typesMap,
            'users_for_filter' => $usersForFilter,
            'filter_store_id'  => $filterStoreId,
            'filter_month'     => $filterMonth,
            'filter_user_id'   => $filterUserId,
            'sort'             => $sort,
            'can_manage'       => true,
        ])->render());
    }

    /**
     * GET /admin/shifts/calendar — vue calendrier mensuel.
     * Paramètres : month (YYYY-MM), u[] (filtre user IDs), store_id
     */
    public function shiftsCalendar(Request $request): Response
    {
        $managedIds = $this->managedIds($request);

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

        // Tous les membres accessibles (avec leur couleur)
        $allMembers  = [];
        $membersMap  = []; // uid → user row
        $usersColour = []; // uid → color
        foreach ($this->availableStores($managedIds) as $store) {
            foreach (StoreUser::findByStore((int) $store['id']) as $m) {
                $uid = (int) $m['user_id'];
                if (isset($membersMap[$uid])) continue;
                $u = $this->users->findById($uid);
                if (!$u || !empty($u['deleted_at']) || (int) ($u['is_active'] ?? 1) !== 1) continue;
                $membersMap[$uid]  = $u;
                $usersColour[$uid] = $u['color'] ?? '#6366f1';
                $allMembers[]      = $u;
            }
        }
        usort($allMembers, fn($a, $b) => strcmp(
            strtolower($a['display_name'] ?? $a['email'] ?? ''),
            strtolower($b['display_name'] ?? $b['email'] ?? '')
        ));

        // Filtre utilisateurs (GET u[])
        $filterUids = array_map('intval', (array) ($request->query('u') ?? []));
        $activeUids = $filterUids ?: array_keys($membersMap);

        // Store filter (optionnel)
        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        $storesMap     = [];
        foreach ($this->availableStores($managedIds) as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? '';
        }

        // Jour de début de semaine (paramètre du store sélectionné ou premier accessible)
        $wsdStore     = $filterStoreId > 0
            ? $this->stores->findById($filterStoreId)
            : ($this->availableStores($managedIds)[0] ?? null);
        $weekStartDay = max(1, min(7, (int) ($wsdStore['week_start_day'] ?? 1)));

        // Charger les shifts du mois pour les utilisateurs actifs via le service
        $shiftsByDate = array_filter(array_map(
            fn(array $dayShifts): array => $this->withoutForeignDrafts($request, $dayShifts),
            $this->shiftService->getCalendarData($activeUids, $monthStart, $monthEnd, $filterStoreId),
        ));

        // Congés approuvés du mois pour les utilisateurs actifs
        $timeoffByDate = $this->shiftService->getTimeoffForCalendar($activeUids, $monthStart, $monthEnd);

        // Types de shift
        $typesMap = $this->shiftService->getShiftTypesMap();

        return response($this->view->make('scheduling.shifts-calendar', [
            'title'         => 'Calendrier',
            'year'          => $year,
            'month'         => $month,
            'month_label'   => $monthLabel,
            'month_start'   => $monthStart,
            'month_end'     => $monthEnd,
            'prev_month'    => $prevMonth,
            'next_month'    => $nextMonth,
            'today'         => date('Y-m-d'),
            'shifts_by_date' => $shiftsByDate,
            'timeoff_by_date' => $timeoffByDate,
            'all_members'   => $allMembers,
            'members_map'   => $membersMap,
            'users_colour'  => $usersColour,
            'filter_uids'   => $filterUids,
            'types_map'     => $typesMap,
            'stores_map'    => $storesMap,
            'filter_store_id' => $filterStoreId,
            'week_start_day'  => $weekStartDay,
            'is_manager_view' => true,
        ])->render());
    }

    /**
     * GET /admin/shifts/timeline — vue Timeline pour les managers/admins.
     * Paramètres : store_id, start (Y-m-d), view (week|3days)
     */
    public function shiftsTimeline(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $today      = date('Y-m-d');

        // Mode d'affichage
        $viewMode = $request->query('view') ?? 'week';
        if (!in_array($viewMode, ['week', '3days'], true)) $viewMode = 'week';

        // Plage de dates
        $startParam = (string) ($request->query('start') ?? $today);
        try {
            $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)
                ? new \DateTimeImmutable($startParam)
                : new \DateTimeImmutable($today);
        } catch (\Throwable) {
            $anchor = new \DateTimeImmutable($today);
        }

        // Stores accessibles (nécessaire avant le calcul du week_start_day)
        $availStores = $this->availableStores($managedIds);
        $storesMap   = [];
        foreach ($availStores as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }

        // Store sélectionné (par défaut : premier store accessible)
        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        if ($filterStoreId === 0 && !empty($availStores)) {
            $filterStoreId = (int) $availStores[0]['id'];
        }
        if ($filterStoreId > 0) {
            try {
                $this->assertStoreAccess($request, $filterStoreId);
            } catch (ForbiddenException) {
                $filterStoreId = 0;
            }
        }

        // Jour de début de semaine selon le store sélectionné
        $storeObjEarly = $filterStoreId > 0 ? $this->stores->findById($filterStoreId) : ($availStores[0] ?? null);
        $weekStartDay  = max(1, min(7, (int) ($storeObjEarly['week_start_day'] ?? 1)));

        // Plage de dates selon le mode et le jour de début de semaine
        if ($viewMode === 'week') {
            $anchorDow = (int) $anchor->format('N'); // 1=Lun … 7=Dim (ISO)
            $offset    = ($anchorDow - $weekStartDay + 7) % 7;
            $firstDay  = $anchor->modify('-' . $offset . ' days');
            $numDays   = 7;
        } else {
            $firstDay = $anchor;
            $numDays  = 3;
        }

        $days      = array_map(fn($i) => $firstDay->modify("+$i days"), range(0, $numDays - 1));
        $lastDay   = $days[$numDays - 1];
        $prevStart = $firstDay->modify("-{$numDays} days")->format('Y-m-d');
        $nextStart = $firstDay->modify("+{$numDays} days")->format('Y-m-d');

        // Membres du store (triés par nom)
        // (comptes supprimés ou désactivés écartés : plus de « #90 » sans nom)
        $memberIds = $filterStoreId > 0 ? $this->activeMemberIds($filterStoreId) : [];

        // Map noms et couleurs
        $usersMap    = $this->buildUsersMap();
        $colorMap    = [];
        foreach ($this->users->findAll() as $u) {
            $colorMap[(int) $u['id']] = $u['color'] ?? null;
        }
        usort($memberIds, fn($a, $b) => strcmp($usersMap[$a] ?? '', $usersMap[$b] ?? ''));

        // Types de shifts (tous les stores gérés)
        $typesMap     = [];
        $typeStoreIds = [];
        foreach ($this->shiftTypesForManaged($managedIds) as $t) {
            $tid = (int) $t['id'];
            $typesMap[$tid]     = $t;
            $typeStoreIds[$tid] = ShiftType::getStoreIds($tid);
        }

        // Shifts par date et utilisateur
        $dateFrom         = $firstDay->format('Y-m-d');
        $dateTo           = $lastDay->format('Y-m-d');
        $shiftsByDateUser = [];
        if ($filterStoreId > 0) {
            foreach ($this->withoutForeignDrafts($request, Shift::findByStore($filterStoreId)) as $s) {
                $d = $s['shift_date'] ?? '';
                if ($d < $dateFrom || $d > $dateTo) continue;
                $uid = (int) ($s['user_id'] ?? 0);
                $shiftsByDateUser[$d][$uid][] = $s;
            }
        }

        // Taux horaires et devise
        $ratesMap    = [];
        $currencyMap = [];
        $storeObj    = $filterStoreId > 0 ? $this->stores->findById($filterStoreId) : null;
        $currency      = strtoupper(trim($storeObj['currency'] ?? 'JPY'));
        $currencyStyle = store_currency_style($storeObj);
        foreach ($memberIds as $uid) {
            $currencyMap[$uid] = $currency;
            foreach (UserShiftTypeRate::findByUser($uid) as $r) {
                $ratesMap[$uid][(int) $r['shift_type_id']] = (float) $r['hourly_rate'];
            }
        }

        // ID de l'utilisateur courant (pour highlight éventuel)
        $authUser = $request->attributes->get('auth_user');
        $myUserId = (int) ($authUser['id'] ?? 0);

        // Peut gérer les shifts du store affiché (Owner, ou RBAC accordant shifts.update sur ce
        // store) — la vue défaut à true si cette clé n'est pas fournie (usage historique en dehors
        // de ce contrôleur), donc omettre ce calcul exposait le bouton import/création et le détail
        // des taux horaires à n'importe quel viewer, y compris un simple employé (shifts.view seul).
        $canManage = !empty($authUser['is_admin'])
            || $this->permissions->can($authUser, 'shifts.update', $filterStoreId > 0 ? $filterStoreId : null);

        // Paramètres de planification du store (pour les alertes timeline)
        $storeSettings = [
            'min_staff_per_day'    => (int) ($storeObj['min_staff_per_day']    ?? 0),
            'min_shift_minutes'    => (int) ($storeObj['min_shift_minutes']    ?? 0),
            'max_shift_minutes'    => (int) ($storeObj['max_shift_minutes']    ?? 0),
            'low_staff_hour_start' => (int) ($storeObj['low_staff_hour_start'] ?? -1),
            'low_staff_hour_end'   => (int) ($storeObj['low_staff_hour_end']   ?? -1),
        ];

        return response($this->view->make('scheduling.shifts-timeline', [
            'title'               => 'Planning — Timeline',
            'days'                => $days,
            'period_mode'         => $viewMode,
            'prev_start'          => $prevStart,
            'next_start'          => $nextStart,
            'shifts_by_date_user' => $shiftsByDateUser,
            'all_user_ids'        => $memberIds,
            'users_map'           => $usersMap,
            'user_color_map'      => $colorMap,
            'types_map'           => $typesMap,
            'type_store_ids'      => $typeStoreIds,
            'rates_map'           => $ratesMap,
            'currency_map'        => $currencyMap,
            'currency_symbol_style' => $currencyStyle,
            'filter_store_id'     => $filterStoreId,
            'stores_map'          => $storesMap,
            'available_stores'    => $availStores,
            'today'               => $today,
            'my_user_id'          => $myUserId,
            'can_manage'          => $canManage,
            'store_settings'      => $storeSettings,
            // Publication du planning (bandeau « brouillon » et bouton Publier), pour qui gère le magasin affiché.
            'publication'         => $canManage && $filterStoreId > 0 && ($this->publication?->isEnabled($filterStoreId) ?? false)
                ? ['until' => $this->publication->publishedUntil($filterStoreId)]
                : null,
            'week_start_day'      => $weekStartDay,
            // Semaine type (copie de la semaine précédente, modèles) : vue semaine, pour qui gère le magasin.
            'week_tools'          => $canManage && $filterStoreId > 0 && $viewMode === 'week'
                ? ['templates' => ShiftWeekTemplate::where('store_id', $filterStoreId)->orderBy('name')->get(), 'templates_enabled' => true]
                : null,
            'all_users'           => $this->users->findAll(),
            'all_stores'          => $availStores,
            'all_types'           => $this->shiftTypesForManaged($managedIds),
        ])->render());
    }

    /**
     * POST /admin/shifts/publish — publie le planning du magasin jusqu'au jour `until` inclus (voir SchedulePublication) :
     * les employés voient les shifts devenus visibles et chacun reçoit une notification qui les résume.
     */
    public function publishSchedule(Request $request): Response
    {
        $storeId = (int) $request->post('store_id', 0);
        $until   = (string) $request->post('until', '');
        $this->assertStoreAccess($request, $storeId);
        $this->permissions->requireOnStore($request->attributes->get('auth_user') ?? [], 'shifts.update', $storeId);

        $back = safe_redirect_path((string) $request->post('redirect_to', ''), '')
            ?: $this->base() . '/admin/shifts/timeline?store_id=' . $storeId;
        if ($this->publication === null || !$this->publication->isEnabled($storeId)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            return redirect()->away($back)->with('error', 'publish_invalid');
        }

        $from   = $this->publication->publishedUntil($storeId);
        $result = $this->publication->publish($storeId, $until);
        $this->auditLogger->log($request, 'schedule.published', 'store', $storeId, [
            'from'      => $from,
            'until'     => $until,
            'published' => $result['published'],
            'notified'  => $result['notified'],
        ], $storeId);

        return redirect()->away($back)->with('success', 'published')->with('notified', $result['notified']);
    }

    /**
     * GET /admin/shifts/timeline/print — Document d'impression autonome de la timeline.
     * Accepte les mêmes paramètres que shiftsTimeline (store_id, start, view).
     */
    public function shiftsTimelinePrint(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $today      = date('Y-m-d');

        $viewMode = $request->query('view') ?? 'week';
        if (!in_array($viewMode, ['week', '3days'], true)) $viewMode = 'week';

        $startParam = (string) ($request->query('start') ?? $today);
        try {
            $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)
                ? new \DateTimeImmutable($startParam)
                : new \DateTimeImmutable($today);
        } catch (\Throwable) {
            $anchor = new \DateTimeImmutable($today);
        }

        $availStores = $this->availableStores($managedIds);
        $storesMap   = [];
        foreach ($availStores as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }

        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        if ($filterStoreId === 0 && !empty($availStores)) {
            $filterStoreId = (int) $availStores[0]['id'];
        }
        if ($filterStoreId > 0) {
            try {
                $this->assertStoreAccess($request, $filterStoreId);
            } catch (ForbiddenException) {
                $filterStoreId = 0;
            }
        }

        // Jour de début de semaine selon le store
        $printStoreObj = $filterStoreId > 0 ? $this->stores->findById($filterStoreId) : ($availStores[0] ?? null);
        $weekStartDay  = max(1, min(7, (int) ($printStoreObj['week_start_day'] ?? 1)));

        if ($viewMode === 'week') {
            $anchorDow = (int) $anchor->format('N');
            $offset    = ($anchorDow - $weekStartDay + 7) % 7;
            $firstDay  = $anchor->modify('-' . $offset . ' days');
            $numDays   = 7;
        } else {
            $firstDay = $anchor;
            $numDays  = 3;
        }

        $days    = array_map(fn($i) => $firstDay->modify("+$i days"), range(0, $numDays - 1));
        $lastDay = $days[$numDays - 1];

        $memberIds = $filterStoreId > 0 ? $this->activeMemberIds($filterStoreId) : [];

        $usersMap = $this->buildUsersMap();
        $colorMap = [];
        foreach ($this->users->findAll() as $u) {
            $colorMap[(int) $u['id']] = $u['color'] ?? null;
        }
        usort($memberIds, fn($a, $b) => strcmp($usersMap[$a] ?? '', $usersMap[$b] ?? ''));

        $typesMap = [];
        foreach ($this->shiftTypesForManaged($managedIds) as $t) {
            $typesMap[(int) $t['id']] = $t;
        }

        $dateFrom         = $firstDay->format('Y-m-d');
        $dateTo           = $lastDay->format('Y-m-d');
        $shiftsByDateUser = [];
        if ($filterStoreId > 0) {
            foreach (Shift::findByStore($filterStoreId) as $s) {
                $d = $s['shift_date'] ?? '';
                if ($d < $dateFrom || $d > $dateTo) continue;
                $uid = (int) ($s['user_id'] ?? 0);
                $shiftsByDateUser[$d][$uid][] = $s;
            }
        }

        return response($this->view->make('scheduling.shifts-timeline-print', [
            'days'                => $days,
            'period_mode'         => $viewMode,
            'shifts_by_date_user' => $shiftsByDateUser,
            'all_user_ids'        => $memberIds,
            'users_map'           => $usersMap,
            'user_color_map'      => $colorMap,
            'types_map'           => $typesMap,
            'filter_store_id'     => $filterStoreId,
            'stores_map'          => $storesMap,
            'today'               => $today,
            'autoprint'           => $request->query('autoprint') === '1',
            'showLegend'          => $request->query('legend') === '1',
        ])->render());
    }

    public function createShift(Request $request): Response
    {
        $managedIds    = $this->managedIds($request);
        $availStores   = $this->availableStores($managedIds);
        $availStoreIds = array_map(fn($s) => (int) $s['id'], $availStores);

        // Utilisateurs : membres des stores accessibles uniquement
        $allUsers = $managedIds !== null
            ? array_values(array_filter($this->users->findAll(), fn($u) => in_array((int) $u['id'], $this->memberUserIds($managedIds), true)))
            : $this->users->findAll();

        $redirectTo = safe_redirect_path((string) $request->query('redirect_to', ''), '');

        return response($this->view->make('scheduling.shifts-form', [
            'title'       => 'Nouveau shift',
            'mode'        => 'create',
            'shift'       => ['is_open' => $request->query('is_open') === '1' ? 1 : 0],
            'all_users'   => $allUsers,
            'all_stores'  => $availStores,
            'all_types'   => $this->shiftTypesForManaged($managedIds),
            'redirect_to' => $redirectTo,
        ])->render());
    }

    /**
     * GET /admin/shifts/wage-preview — aperçu en direct de la décomposition par
     * tranche horaire pendant la saisie du formulaire (remplace l'ancien select
     * manuel de shift_type_id, supprimé car un shift peut chevaucher plusieurs
     * types). Mêmes règles de résolution des taux que ShiftWageCalculator::costOf().
     */
    public function wagePreview(Request $request): Response
    {
        $storeId = (int) $request->query('store_id', 0);
        $this->assertStoreAccess($request, $storeId);

        $startTime = (string) $request->query('start_time', '');
        $endTime   = (string) $request->query('end_time', '');
        if ($startTime === '' || $endTime === '') {
            return response()->json(['breakdown' => [], 'estimated_salary' => 0.0, 'dominant_type_id' => null]);
        }

        $start = strtotime($startTime);
        $end   = strtotime($endTime);
        $duration = (int) (($end - $start) / 60);
        if ($duration < 0) {
            $duration += 24 * 60;
        }
        $pauseMinutes = (int) $request->query('pause_minutes', 0);
        $netMinutes = max(0, $duration - $pauseMinutes);

        $userId = (int) $request->query('user_id', 0);
        $personalRates = [];
        if ($userId > 0) {
            foreach (UserShiftTypeRate::findByUser($userId) as $r) {
                $personalRates[(int) $r['shift_type_id']] = (float) $r['hourly_rate'];
            }
        }

        $wageCalc = new ShiftWageCalculator();
        $types    = $wageCalc->withPersonalRates(
            array_column(ShiftType::findActive($storeId), null, 'id'),
            $personalRates
        );
        $result = $wageCalc->calculate($startTime, $endTime, $types, $netMinutes);

        return response()->json($result);
    }

    public function storeShift(Request $request): Response
    {
        $storeId = (int) $request->post('store_id', 0);
        $this->assertStoreAccess($request, $storeId);

        $startTime = $request->post('start_time', '08:00');
        $endTime   = $request->post('end_time', '16:00');

        $shiftDate = $request->post('shift_date', '');

        $start = strtotime($startTime);
        $end   = strtotime($endTime);
        $duration = (int) (($end - $start) / 60);
        if ($duration < 0) {
            $duration += 24 * 60;
        }
        $crossMidnight = ($end < $start) ? 1 : 0;
        $endsDate = $crossMidnight ? date('Y-m-d', strtotime($shiftDate . ' +1 day')) : $shiftDate;

        // Champs des sections de bundles (bourse aux shifts…, contrat 1.17) : pris en compte seulement s'ils sont déclarés.
        $sectionFields = ShiftFormSections::current()->fields();
        $isOpen = in_array('is_open', $sectionFields, true) && $request->post('is_open') === '1';
        $uid    = (int) $request->post('user_id', 0);

        if (!$isOpen && $uid > 0 && $this->hasApprovedTimeoffConflict($uid, $shiftDate)) {
            return redirect()->away($this->base() . '/admin/shifts/create?store_id=' . $storeId)->with('error', 'timeoff_conflict');
        }

        if (!$isOpen && $uid > 0 && !$this->availabilityForced($request)) {
            $conflict = $this->availabilityConflict($uid, $storeId, $shiftDate);
            if ($conflict !== null) {
                return $this->shiftFormWithAvailabilityConflict($request, 'create', $this->postedShiftFields($request), $conflict);
            }
        }

        $pauseMinutes = (int) $request->post('pause_minutes', 0);
        $shiftTypeId  = $this->dominantShiftTypeId($storeId, $startTime, $endTime, max(0, $duration - $pauseMinutes));

        $saved = Shift::put([
            'store_id'         => $storeId,
            'user_id'          => ($isOpen || $uid === 0) ? null : $uid,
            'shift_type_id'    => $shiftTypeId,
            'shift_date'       => $shiftDate,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'duration_minutes' => $duration,
            'pause_minutes'    => $pauseMinutes,
            'cross_midnight'   => $crossMidnight,
            'starts_at'        => $shiftDate . ' ' . $startTime . ':00',
            'ends_at'          => $endsDate  . ' ' . $endTime   . ':00',
            'notes'            => $request->post('notes', '') ?: null,
            'is_open'          => $isOpen ? 1 : 0,
            'open_shift_note'  => in_array('open_shift_note', $sectionFields, true) ? ($request->post('open_shift_note', '') ?: null) : null,
            'hourly_rate_override' => $this->postedOverride($request, 'hourly_rate_override'),
            'net_minutes_override' => $this->postedOverride($request, 'net_minutes_override'),
        ]);

        $this->auditLogger->log($request, 'shift.created', 'shift', (int) ($saved['id'] ?? 0), [
            'store_id' => $storeId,
            'shift_date' => $shiftDate,
            'start' => $startTime,
            'end' => $endTime,
        ], $storeId);

        // Le shift est déjà sauvegardé (et audité) à ce stade : un échec de notification
        // ne doit pas transformer une action réussie en 500 générique côté utilisateur.
        if (!$isOpen && $uid > 0 && $this->isPublishedDay($storeId, $shiftDate)) {
            try {
                $this->notifs->notify(
                    $uid,
                    'shift_assigned',
                    'notif_shift_assigned_body',
                    $this->shiftNotificationReplace($shiftDate, $startTime, $endTime, $storeId),
                    (int) ($saved['id'] ?? 0),
                    $this->shiftNotificationLink($shiftDate, (int) ($saved['id'] ?? 0))
                );
            } catch (\Throwable $e) {
                Log::warning('shift_notify_failed', ['shift_id' => (int) ($saved['id'] ?? 0), 'user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }

        $redirectTo = safe_redirect_path((string) $request->post('redirect_to', ''), '');
        if ($redirectTo !== '') {
            return redirect()->away($redirectTo)->with('success', 'created');
        }

        return redirect()->away($this->base() . '/admin/shifts')->with('success', 'created');
    }

    public function editShift(Request $request): Response
    {
        $shift = Shift::findById((int) $request->route('id'));
        if ($shift === null) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        $this->assertStoreAccess($request, (int) $shift['store_id']);

        $managedIds = $this->managedIds($request);
        $allUsers   = $managedIds !== null
            ? array_values(array_filter($this->users->findAll(), fn($u) => in_array((int) $u['id'], $this->memberUserIds($managedIds), true)))
            : $this->users->findAll();

        $redirectTo = safe_redirect_path((string) $request->query('redirect_to', ''), '');

        return response($this->view->make('scheduling.shifts-form', [
            'title'       => 'Modifier le shift #' . $shift['id'],
            'mode'        => 'edit',
            'shift'       => $shift,
            'all_users'   => $allUsers,
            'all_stores'  => $this->availableStores($managedIds),
            'all_types'   => $this->shiftTypesForManaged($managedIds),
            'redirect_to' => $redirectTo,
        ])->render());
    }

    public function updateShift(Request $request): Response
    {
        $shift = Shift::findById((int) $request->route('id'));
        if ($shift === null) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        $this->assertStoreAccess($request, (int) $shift['store_id']);
        $old = $shift;

        $newStoreId = (int) $request->post('store_id', $shift['store_id']);
        // Vérifier aussi le store cible si changement
        if ($newStoreId !== (int) $shift['store_id']) {
            $this->assertStoreAccess($request, $newStoreId);
        }

        $startTime = $request->post('start_time', $shift['start_time']);
        $endTime   = $request->post('end_time', $shift['end_time']);

        $start = strtotime($startTime);
        $end   = strtotime($endTime);
        $duration = (int) (($end - $start) / 60);
        if ($duration < 0) {
            $duration += 24 * 60;
        }
        $crossMidnight = ($end < $start) ? 1 : 0;

        $shiftDate = $request->post('shift_date', $shift['shift_date'] ?? '');
        $endsDate  = $crossMidnight ? date('Y-m-d', strtotime($shiftDate . ' +1 day')) : $shiftDate;

        // Champs des sections de bundles (bourse aux shifts…, contrat 1.17), seulement s'ils sont déclarés ; champ absent :
        // état actuel conservé (audit du 13/10/2026, M12).
        $sectionFields = ShiftFormSections::current()->fields();
        $wasOpen = (int) ($shift['is_open'] ?? 0) === 1;
        $isOpen  = in_array('is_open', $sectionFields, true)
            ? $request->postFlag('is_open', $shift['is_open'] ?? 0) === 1
            : $wasOpen;

        $targetUid = $isOpen ? 0 : (int) $request->post('user_id', $shift['user_id']);
        if (!$isOpen && $targetUid > 0 && $this->hasApprovedTimeoffConflict($targetUid, $shiftDate)) {
            return redirect()->away($this->base() . '/admin/shifts/' . $shift['id'] . '/edit')->with('error', 'timeoff_conflict');
        }

        // Seulement quand la personne ou la date change : une simple retouche d'horaire ne doit pas redemander.
        $moved = $targetUid !== (int) ($shift['user_id'] ?? 0) || $shiftDate !== (string) ($shift['shift_date'] ?? '');
        if (!$isOpen && $targetUid > 0 && $moved && !$this->availabilityForced($request)) {
            $conflict = $this->availabilityConflict($targetUid, $newStoreId, $shiftDate);
            if ($conflict !== null) {
                return $this->shiftFormWithAvailabilityConflict($request, 'edit', array_merge($shift, $this->postedShiftFields($request)), $conflict);
            }
        }

        $pauseMinutes = (int) $request->post('pause_minutes', $shift['pause_minutes'] ?? 0);
        $shiftTypeId  = $this->dominantShiftTypeId($newStoreId, $startTime, $endTime, max(0, $duration - $pauseMinutes));

        $saved = Shift::put(array_merge($shift, [
            'store_id'         => $newStoreId,
            'user_id'          => $isOpen ? null : $targetUid,
            'shift_type_id'    => $shiftTypeId,
            'shift_date'       => $shiftDate,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'duration_minutes' => $duration,
            'pause_minutes'    => $pauseMinutes,
            'cross_midnight'   => $crossMidnight,
            'starts_at'        => $shiftDate . ' ' . $startTime . ':00',
            'ends_at'          => $endsDate  . ' ' . $endTime   . ':00',
            'notes'            => $request->hasPost('notes') ? ($request->post('notes', '') ?: null) : ($shift['notes'] ?? null),
            'is_open'          => $isOpen ? 1 : 0,
            'open_shift_note'  => in_array('open_shift_note', $sectionFields, true) && $request->hasPost('open_shift_note') ? ($request->post('open_shift_note', '') ?: null) : ($shift['open_shift_note'] ?? null),
            'hourly_rate_override' => $request->hasPost('hourly_rate_override') ? $this->postedOverride($request, 'hourly_rate_override') : ($shift['hourly_rate_override'] ?? null),
            'net_minutes_override' => $request->hasPost('net_minutes_override') ? $this->postedOverride($request, 'net_minutes_override') : ($shift['net_minutes_override'] ?? null),
        ]));

        $this->auditLogger->logUpdate($request, 'shift.updated', 'shift', (int) $shift['id'], $old, $saved, [], $newStoreId);

        // Shift retiré de la bourse : les bundles qui l'écoutent (shift-claim retire les candidatures en attente).
        if ($wasOpen && !$isOpen) {
            $authId = (int) ($request->attributes->get('auth_user')['id'] ?? 0);
            Events::current()->dispatch(new OpenShiftClosed($saved, $authId > 0 ? $authId : null));
        }

        $newUid    = $isOpen ? 0 : (int) $request->post('user_id', $shift['user_id']);
        $prevUid   = (int) ($shift['user_id'] ?? 0);
        // Publication du planning : rien à annoncer sur un brouillon ; un shift publié renvoyé en brouillon disparaît du
        // planning de l'employé, qui en est prévenu comme d'une suppression.
        $wasPublished = $this->isPublishedDay((int) ($old['store_id'] ?? 0), (string) ($old['shift_date'] ?? ''));
        $isPublished  = $this->isPublishedDay($newStoreId, (string) $shiftDate);
        if (!$isPublished) {
            if ($wasPublished && $prevUid > 0 && (int) ($old['is_open'] ?? 0) !== 1) {
                $this->notifyShiftDeleted($old);
            }
        } elseif (!$isOpen && $newUid > 0 && ($newUid !== $prevUid || !$wasPublished)) {
            try {
                $this->notifs->notify(
                    $newUid,
                    'shift_assigned',
                    'notif_shift_assigned_body',
                    $this->shiftNotificationReplace($shiftDate, $startTime, $endTime, $newStoreId),
                    (int) $shift['id'],
                    $this->shiftNotificationLink($shiftDate, (int) $shift['id'])
                );
            } catch (\Throwable $e) {
                Log::warning('shift_notify_failed', ['shift_id' => (int) $shift['id'], 'user_id' => $newUid, 'error' => $e->getMessage()]);
            }
        } elseif (!$isOpen && $newUid > 0 && $newUid === $prevUid) {
            // Même employé conservé : le prévenir quand même si la date/l'horaire/le
            // store a réellement changé — un shift déplacé sans réassignation restait
            // jusqu'ici silencieux (seul un changement d'employé notifiait).
            $rescheduled = $old['shift_date'] !== $saved['shift_date']
                || $old['start_time'] !== $saved['start_time']
                || $old['end_time'] !== $saved['end_time']
                || (int) ($old['store_id'] ?? 0) !== (int) ($saved['store_id'] ?? 0);
            if ($rescheduled) {
                try {
                    $this->notifs->notify(
                        $newUid,
                        'shift_updated',
                        'notif_shift_updated_body',
                        $this->shiftNotificationReplace($shiftDate, $startTime, $endTime, $newStoreId),
                        (int) $shift['id'],
                        $this->shiftNotificationLink($shiftDate, (int) $shift['id'])
                    );
                } catch (\Throwable $e) {
                    Log::warning('shift_notify_failed', ['shift_id' => (int) $shift['id'], 'user_id' => $newUid, 'error' => $e->getMessage()]);
                }
            }
        }

        $redirectTo = safe_redirect_path((string) $request->post('redirect_to', ''), '');
        if ($redirectTo !== '') {
            return redirect()->away($redirectTo)->with('success', 'updated');
        }

        return redirect()->away($this->base() . '/admin/shifts')->with('success', 'updated');
    }

    /**
     * Prévient l'employé qu'un de ses shifts a été supprimé (suppression directe ou résolution d'un conflit). Le shift
     * est déjà supprimé et audité à ce stade : un échec de notification ne doit pas transformer une action réussie en
     * 500 générique côté utilisateur.
     */
    private function notifyShiftDeleted(array $shift): void
    {
        // Brouillon supprimé : l'employé ne l'a jamais vu.
        if (empty($shift['user_id']) || !$this->isPublishedDay((int) ($shift['store_id'] ?? 0), (string) ($shift['shift_date'] ?? ''))) {
            return;
        }
        try {
            $this->notifs->notify(
                (int) $shift['user_id'],
                'shift_deleted',
                'notif_shift_deleted_body',
                $this->shiftNotificationReplace(
                    $shift['shift_date'] ?? '',
                    $shift['start_time'] ?? '',
                    $shift['end_time'] ?? '',
                    (int) ($shift['store_id'] ?? 0)
                ),
                shift_day_reference((string) ($shift['shift_date'] ?? '')),
                $this->shiftNotificationLink($shift['shift_date'] ?? '')
            );
        } catch (\Throwable $e) {
            Log::warning('shift_notify_failed', ['shift_id' => $shift['id'] ?? null, 'user_id' => $shift['user_id'], 'error' => $e->getMessage()]);
        }
    }

    public function deleteShift(Request $request): Response
    {
        $id    = (int) $request->route('id');
        $shift = Shift::findById($id);
        if ($shift !== null) {
            $this->assertStoreAccess($request, (int) $shift['store_id']);
        }
        Shift::remove($id);
        $this->auditLogger->log($request, 'shift.deleted', 'shift', $id, [
            'shift_date' => $shift['shift_date'] ?? null,
        ], $shift ? (int) $shift['store_id'] : null);

        if ($shift !== null) {
            $this->notifyShiftDeleted($shift);
        }

        $redirectTo = safe_redirect_path((string) $request->post('redirect_to', ''), '');
        if ($redirectTo !== '') {
            return redirect()->away($redirectTo)->with('success', 'conflict_resolved');
        }
        return redirect()->away($this->base() . '/admin/shifts')->with('success', 'deleted');
    }

    /**
     * POST /admin/shifts/bulk-delete — supprime plusieurs shifts en une opération.
     * Body : ids[] = tableau d'IDs, redirect_month, redirect_store
     */
    public function bulkDeleteShifts(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $ids        = $request->post('ids') ?? [];
        if (!is_array($ids) || empty($ids)) {
            return redirect()->away($this->base() . '/admin/shifts');
        }
        // Limite côté serveur : 100 IDs maximum
        $ids = array_slice($ids, 0, 100);

        $deleted    = 0;
        $deletedIds = [];
        foreach ($ids as $rawId) {
            $id    = (int) $rawId;
            if ($id <= 0) continue;
            $shift = Shift::findById($id);
            if (!$shift) continue;
            // Vérification d'accès au store
            $storeId = (int) ($shift['store_id'] ?? 0);
            if ($managedIds !== null && !in_array($storeId, $managedIds, true)) continue;
            Shift::remove($id);
            $deletedIds[] = $id;
            $deleted++;

            if (!empty($shift['user_id']) && $this->isPublishedDay($storeId, (string) ($shift['shift_date'] ?? ''))) {
                try {
                    $this->notifs->notify(
                        (int) $shift['user_id'],
                        'shift_deleted',
                        'notif_shift_deleted_body',
                        $this->shiftNotificationReplace(
                            $shift['shift_date'] ?? '',
                            $shift['start_time'] ?? '',
                            $shift['end_time'] ?? '',
                            $storeId
                        ),
                        shift_day_reference((string) ($shift['shift_date'] ?? '')),
                        $this->shiftNotificationLink($shift['shift_date'] ?? '')
                    );
                } catch (\Throwable $e) {
                    Log::warning('shift_notify_failed', ['shift_id' => $id, 'user_id' => $shift['user_id'], 'error' => $e->getMessage()]);
                }
            }
        }

        if ($deleted > 0) {
            $this->auditLogger->log($request, 'shift.bulk_deleted', 'shift', null, [
                'ids' => $deletedIds,
                'count' => $deleted,
            ]);
        }

        $month = $request->post('redirect_month') ?? date('Y-m');
        $store = (int) ($request->post('redirect_store') ?? 0);
        $qs    = http_build_query(array_filter([
            'month'    => $month,
            'store_id' => $store ?: null,
            'success'  => 'bulk_deleted',
            'count'    => $deleted,
        ], fn($v) => $v !== null && $v !== 0 && $v !== ''));
        return redirect()->away($this->base() . '/admin/shifts?' . $qs);
    }

    /**
     * POST /admin/shifts/quick — Création rapide JSON (drag & drop timeline).
     */
    public function quickShift(Request $request): Response
    {
        $body    = $request->json()->all() ?? [];
        $storeId = (int) ($body['store_id'] ?? 0);

        try {
            $this->assertStoreAccess($request, $storeId);
        } catch (ForbiddenException) {
            return response()->json(['error' => __('error_access_denied')], 403);
        }

        $shiftDate = $body['shift_date'] ?? '';
        $startTime = $body['start_time'] ?? '08:00';
        $endTime   = $body['end_time']   ?? '16:00';

        $start         = strtotime($startTime);
        $end           = strtotime($endTime);
        $duration      = (int) (($end - $start) / 60);
        if ($duration < 0) $duration += 24 * 60;
        $crossMidnight = ($end < $start) ? 1 : 0;
        $endsDate      = $crossMidnight
            ? date('Y-m-d', strtotime($shiftDate . ' +1 day'))
            : $shiftDate;

        $pauseMin    = (int) ($body['pause_minutes'] ?? 0);
        $notes       = trim($body['notes'] ?? '') ?: null;
        $sectionFields = ShiftFormSections::current()->fields();
        $openNote    = in_array('open_shift_note', $sectionFields, true) ? (trim($body['open_shift_note'] ?? '') ?: null) : null;
        $isOpen      = in_array('is_open', $sectionFields, true) && !empty($body['is_open']) ? 1 : 0;
        $bodyUid     = (int) ($body['user_id'] ?? 0);

        if (!$isOpen && $bodyUid > 0 && $this->hasApprovedTimeoffConflict($bodyUid, $shiftDate)) {
            return response()->json(['error' => __('shift_timeoff_conflict')], 409);
        }

        if (!$isOpen && $bodyUid > 0 && empty($body['force_availability'])) {
            $conflict = $this->availabilityConflict($bodyUid, $storeId, $shiftDate);
            if ($conflict !== null) {
                return response()->json(['error' => $this->availabilityMessage($conflict), 'code' => 'availability_conflict'], 409);
            }
        }

        $shiftTypeId = $this->dominantShiftTypeId($storeId, $startTime, $endTime, max(0, $duration - $pauseMin));

        $saved = Shift::put([
            'store_id'         => $storeId,
            'user_id'          => (int) ($body['user_id'] ?? 0),
            'shift_type_id'    => $shiftTypeId,
            'shift_date'       => $shiftDate,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'duration_minutes' => $duration,
            'pause_minutes'    => $pauseMin,
            'cross_midnight'   => $crossMidnight,
            'starts_at'        => $shiftDate . ' ' . $startTime . ':00',
            'ends_at'          => $endsDate  . ' ' . $endTime   . ':00',
            'notes'            => $notes,
            'open_shift_note'  => $openNote,
            'is_open'          => $isOpen,
        ]);

        $this->auditLogger->log($request, 'shift.created', 'shift', (int) ($saved['id'] ?? 0), [
            'store_id' => $storeId,
            'shift_date' => $shiftDate,
            'start' => $startTime,
            'end' => $endTime,
            'via' => 'drag',
        ], $storeId);

        return response()->json($saved, 201);
    }

    /**
     * GET /admin/shifts/conflicts — détecte les chevauchements de shifts par employé.
     */
    public function shiftConflicts(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $usersMap   = $this->buildUsersMap();

        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        $filterMonth   = $request->query('month') ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) $filterMonth = '';

        // Avec un mois, seule sa plage est lue en base ; sans mois, tout l'historique reste nécessaire.
        $allShifts = $filterMonth !== ''
            ? Shift::findBetween($managedIds, $filterMonth . '-01', date('Y-m-t', strtotime($filterMonth . '-01')))
            : $this->filterByStore(Shift::findAll(), $managedIds);

        if ($filterStoreId > 0) {
            $allShifts = array_values(array_filter(
                $allShifts,
                fn($s) => (int) ($s['store_id'] ?? 0) === $filterStoreId
            ));
        }

        if ($filterMonth !== '') {
            $mStart    = $filterMonth . '-01';
            $mEnd      = date('Y-m-t', strtotime($mStart));
            $allShifts = array_values(array_filter(
                $allShifts,
                fn($s) => ($s['shift_date'] ?? '') >= $mStart && ($s['shift_date'] ?? '') <= $mEnd
            ));
        }

        // Détection des conflits par employé (chevauchements temporels)
        $byUser = [];
        foreach ($allShifts as $s) {
            $uid = (int) ($s['user_id'] ?? 0);
            if ($uid > 0) $byUser[$uid][] = $s;
        }

        $conflicts = [];
        foreach ($byUser as $userId => $userShifts) {
            usort($userShifts, fn($a, $b) => strcmp(
                ($a['shift_date'] ?? '') . ($a['start_time'] ?? ''),
                ($b['shift_date'] ?? '') . ($b['start_time'] ?? '')
            ));

            $n = count($userShifts);
            for ($i = 0; $i < $n; $i++) {
                $a      = $userShifts[$i];
                $startA = strtotime(($a['shift_date'] ?? '') . ' ' . ($a['start_time'] ?? '00:00'));
                $endA   = !empty($a['cross_midnight'])
                    ? strtotime(date('Y-m-d', strtotime(($a['shift_date'] ?? '') . ' +1 day')) . ' ' . ($a['end_time'] ?? '00:00'))
                    : strtotime(($a['shift_date'] ?? '') . ' ' . ($a['end_time'] ?? '00:00'));

                for ($j = $i + 1; $j < $n; $j++) {
                    $b      = $userShifts[$j];
                    $startB = strtotime(($b['shift_date'] ?? '') . ' ' . ($b['start_time'] ?? '00:00'));
                    if ($startB >= $endA) break; // plus de chevauchement possible
                    $endB = !empty($b['cross_midnight'])
                        ? strtotime(date('Y-m-d', strtotime(($b['shift_date'] ?? '') . ' +1 day')) . ' ' . ($b['end_time'] ?? '00:00'))
                        : strtotime(($b['shift_date'] ?? '') . ' ' . ($b['end_time'] ?? '00:00'));

                    if ($startA < $endB && $startB < $endA) {
                        $overlapMin = (int) ((min($endA, $endB) - max($startA, $startB)) / 60);
                        $conflicts[] = [
                            'user_id'         => $userId,
                            'shift_a'         => $a,
                            'shift_b'         => $b,
                            'overlap_minutes' => $overlapMin,
                        ];
                    }
                }
            }
        }

        // Plus récent en premier
        usort($conflicts, fn($a, $b) => strcmp(
            $b['shift_a']['shift_date'] ?? '',
            $a['shift_a']['shift_date'] ?? ''
        ));

        $storesMap    = [];
        $storesMinMap = []; // store_id → min_shift_minutes (0 = non configuré)
        foreach ($this->availableStores($managedIds) as $s) {
            $sid                = (int) $s['id'];
            $storesMap[$sid]    = $s['name'] ?? '#' . $sid;
            $storesMinMap[$sid] = (int) ($s['min_shift_minutes'] ?? 0);
        }

        $typesMap = [];
        foreach (ShiftType::findAll() as $t) {
            $typesMap[(int) $t['id']] = $t;
        }

        // Shifts trop courts : durée < min_shift_minutes du store (si paramètre > 0)
        $tooShort = [];
        foreach ($allShifts as $s) {
            $sid = (int) ($s['store_id'] ?? 0);
            $min = $storesMinMap[$sid] ?? 0;
            if ($min <= 0) continue;
            $dur = (int) ($s['duration_minutes'] ?? 0);
            if ($dur > 0 && $dur < $min) {
                $tooShort[] = $s;
            }
        }
        usort($tooShort, fn($a, $b) => strcmp(
            ($b['shift_date'] ?? ''),
            ($a['shift_date'] ?? '')
        ));

        return response($this->view->make('scheduling.shifts-conflicts', [
            'title'           => __('shift_conflicts_title'),
            'conflicts'       => $conflicts,
            'too_short'       => $tooShort,
            'stores_min_map'  => $storesMinMap,
            'users_map'       => $usersMap,
            'stores_map'      => $storesMap,
            'types_map'       => $typesMap,
            'filter_store_id' => $filterStoreId,
            'filter_month'    => $filterMonth,
        ])->render());
    }

    /**
     * POST /admin/shifts/conflicts/resolve-newer
     * Conserve le shift le plus récent (ID le plus élevé) et supprime l'ancien.
     *   - Mode unitaire : id_keep + id_delete
     *   - Mode vrac     : bulk=1 + month (YYYY-MM) [+ store_id]
     */
    public function resolveNewerConflict(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $bulk       = $request->post('bulk') === '1';
        $month      = trim($request->post('month', ''));
        $storeId    = (int) $request->post('store_id', 0);

        if (!$bulk) {
            // --- Mode unitaire ---
            $idKeep   = (int) $request->post('id_keep',   0);
            $idDelete = (int) $request->post('id_delete', 0);
            if ($idKeep <= 0 || $idDelete <= 0) {
                return redirect()->away($this->base() . '/admin/shifts/conflicts');
            }

            $shiftKeep   = Shift::findById($idKeep);
            $shiftDelete = Shift::findById($idDelete);
            if ($shiftKeep)   $this->assertStoreAccess($request, (int) $shiftKeep['store_id']);
            if ($shiftDelete) $this->assertStoreAccess($request, (int) $shiftDelete['store_id']);

            // L'un des deux a déjà disparu (double clic, page restée ouverte) : il n'y a plus de conflit à résoudre.
            if ($shiftKeep !== null && $shiftDelete !== null) {
                // Seule la suppression d'un shift réellement en conflit avec celui qu'on garde est permise : sinon la
                // route (shifts.update) servait à supprimer n'importe quel shift de ses magasins.
                if ($idKeep === $idDelete || !self::shiftsOverlap($shiftKeep, $shiftDelete)) {
                    throw new ForbiddenException(__('error_shifts_not_in_conflict'));
                }
                Shift::remove($idDelete);
                $this->auditLogger->logUpdate($request, 'shift.conflict_resolved', 'shift', $idKeep, $shiftDelete, $shiftKeep, ['kept_id' => $idKeep, 'deleted_id' => $idDelete], (int) $shiftKeep['store_id']);
                $this->notifyShiftDeleted($shiftDelete);
            }

            $qs = http_build_query(array_filter([
                'month'    => $month ?: null,
                'store_id' => $storeId ?: null,
                'success'  => 'conflict_resolved',
            ], fn($v) => $v !== null && $v !== 0 && $v !== ''));
            return redirect()->away($this->base() . '/admin/shifts/conflicts?' . $qs);
        }

        // --- Mode vrac : résoudre tous les conflits du mois ---
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return redirect()->away($this->base() . '/admin/shifts/conflicts');
        }

        $mStart    = $month . '-01';
        $mEnd      = date('Y-m-t', strtotime($mStart));
        $allShifts = Shift::findBetween($managedIds, $mStart, $mEnd);

        if ($storeId > 0) {
            $allShifts = array_values(array_filter(
                $allShifts,
                fn($s) => (int) ($s['store_id'] ?? 0) === $storeId
            ));
        }
        $allShifts = array_values(array_filter(
            $allShifts,
            fn($s) => ($s['shift_date'] ?? '') >= $mStart && ($s['shift_date'] ?? '') <= $mEnd
        ));

        $byUser = [];
        foreach ($allShifts as $s) {
            $uid = (int) ($s['user_id'] ?? 0);
            if ($uid > 0) $byUser[$uid][] = $s;
        }

        $toDelete = [];
        foreach ($byUser as $userShifts) {
            usort($userShifts, fn($a, $b) => strcmp(
                ($a['shift_date'] ?? '') . ($a['start_time'] ?? ''),
                ($b['shift_date'] ?? '') . ($b['start_time'] ?? '')
            ));
            $n = count($userShifts);
            for ($i = 0; $i < $n; $i++) {
                $a      = $userShifts[$i];
                $startA = strtotime(($a['shift_date'] ?? '') . ' ' . ($a['start_time'] ?? '00:00'));
                $endA   = !empty($a['cross_midnight'])
                    ? strtotime(date('Y-m-d', strtotime(($a['shift_date'] ?? '') . ' +1 day')) . ' ' . ($a['end_time'] ?? '00:00'))
                    : strtotime(($a['shift_date'] ?? '') . ' ' . ($a['end_time'] ?? '00:00'));

                for ($j = $i + 1; $j < $n; $j++) {
                    $b      = $userShifts[$j];
                    $startB = strtotime(($b['shift_date'] ?? '') . ' ' . ($b['start_time'] ?? '00:00'));
                    if ($startB >= $endA) break;
                    $endB = !empty($b['cross_midnight'])
                        ? strtotime(date('Y-m-d', strtotime(($b['shift_date'] ?? '') . ' +1 day')) . ' ' . ($b['end_time'] ?? '00:00'))
                        : strtotime(($b['shift_date'] ?? '') . ' ' . ($b['end_time'] ?? '00:00'));

                    if ($startA < $endB && $startB < $endA) {
                        // Garder le plus récent (ID le plus élevé), supprimer l'ancien
                        $idOlder = min((int) $a['id'], (int) $b['id']);
                        $toDelete[$idOlder] = true;
                    }
                }
            }
        }

        $deleted = 0;
        $deletedShifts = [];
        foreach (array_keys($toDelete) as $idDel) {
            $shift = Shift::findById($idDel);
            if (!$shift) continue;
            $deletedShifts[] = $shift;
            Shift::remove($idDel);
            $deleted++;
        }

        $this->auditLogger->logUpdate($request, 'shift.bulk_conflict_resolved', 'shift', null, $deletedShifts, [], [
            'month' => $month,
            'store_id' => $storeId,
        ], $storeId ?: null);
        foreach ($deletedShifts as $shift) {
            $this->notifyShiftDeleted($shift);
        }

        $qs = http_build_query(array_filter([
            'month'    => $month,
            'store_id' => $storeId ?: null,
            'success'  => 'conflicts_resolved',
            'count'    => $deleted,
        ], fn($v) => $v !== null && $v !== 0 && $v !== ''));
        return redirect()->away($this->base() . '/admin/shifts/conflicts?' . $qs);
    }

    /**
     * Même règle que la page des conflits : deux shifts d'un même employé dont les plages horaires se chevauchent
     * (un shift qui passe minuit se termine le lendemain).
     */
    private static function shiftsOverlap(array $a, array $b): bool
    {
        $userA = (int) ($a['user_id'] ?? 0);
        if ($userA <= 0 || $userA !== (int) ($b['user_id'] ?? 0)) {
            return false;
        }
        $range = static function (array $s): array {
            $date  = (string) ($s['shift_date'] ?? '');
            $start = strtotime($date . ' ' . ($s['start_time'] ?? '00:00'));
            $end   = !empty($s['cross_midnight'])
                ? strtotime(date('Y-m-d', strtotime($date . ' +1 day')) . ' ' . ($s['end_time'] ?? '00:00'))
                : strtotime($date . ' ' . ($s['end_time'] ?? '00:00'));
            return [$start, $end];
        };
        [$startA, $endA] = $range($a);
        [$startB, $endB] = $range($b);
        return $startA < $endB && $startB < $endA;
    }

    /**
     * POST /admin/shifts/{id}/move — déplace un shift (heure) via drag & drop.
     * Corps JSON : { shift_date?, start_time, end_time }
     * Retourne le shift mis à jour en JSON.
     */
    public function moveShift(Request $request): Response
    {
        $shift = Shift::findById((int) $request->route('id'));
        if ($shift === null) {
            return response()->json(['error' => __('error_shift_not_found')], 404);
        }
        try {
            $this->assertStoreAccess($request, (int) $shift['store_id']);
        } catch (ForbiddenException) {
            return response()->json(['error' => __('error_access_denied')], 403);
        }
        $old = $shift;

        $body      = $request->json()->all() ?? [];
        $shiftDate = $body['shift_date'] ?? $shift['shift_date'];
        $startTime = $body['start_time'] ?? $shift['start_time'];
        $endTime   = $body['end_time']   ?? $shift['end_time'];

        $start         = strtotime($startTime);
        $end           = strtotime($endTime);
        $duration      = (int) (($end - $start) / 60);
        if ($duration < 0) $duration += 24 * 60;
        $crossMidnight = ($end < $start) ? 1 : 0;
        $endsDate      = $crossMidnight
            ? date('Y-m-d', strtotime($shiftDate . ' +1 day'))
            : $shiftDate;

        $moveUid = (int) ($shift['user_id'] ?? 0);
        if (empty($shift['is_open']) && $moveUid > 0 && $this->hasApprovedTimeoffConflict($moveUid, $shiftDate)) {
            return response()->json(['error' => __('shift_timeoff_conflict')], 409);
        }

        if (empty($shift['is_open']) && $moveUid > 0 && $shiftDate !== (string) ($shift['shift_date'] ?? '') && empty($body['force_availability'])) {
            $conflict = $this->availabilityConflict($moveUid, (int) $shift['store_id'], $shiftDate);
            if ($conflict !== null) {
                return response()->json(['error' => $this->availabilityMessage($conflict), 'code' => 'availability_conflict'], 409);
            }
        }

        $saved = Shift::put(array_merge($shift, [
            'shift_date'       => $shiftDate,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'duration_minutes' => $duration,
            'cross_midnight'   => $crossMidnight,
            'starts_at'        => $shiftDate . ' ' . $startTime . ':00',
            'ends_at'          => $endsDate  . ' ' . $endTime   . ':00',
        ]));

        $this->auditLogger->logUpdate($request, 'shift.moved', 'shift', (int) $shift['id'], $old, $saved, [], (int) $shift['store_id']);

        return response()->json($saved);
    }

    /**
     * POST /admin/shifts/quick — création rapide d'un shift via drag & drop.
     * Corps JSON : { store_id, user_id, shift_date, start_time, end_time, shift_type_id? }
     * Retourne le shift créé en JSON (201).
     */
}
