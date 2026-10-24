<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Extension\ProfileSections;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\LocaleCookie;
use kintai\Core\Services\NotificationMailer;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvatarImageOptimizer;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\CalendarSyncRepositoryInterface;
use kintai\Core\Services\Calendar\GoogleCalendarSettings;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class AuthController
{
    use HasBaseUrl;
    private const NAV_OWNER_SECTIONS   = ['planning', 'hr', 'requests', 'statistics', 'system'];
    private const NAV_MANAGER_SECTIONS = ['planning', 'hr', 'requests', 'statistics'];

    // Entrées masquables : calculées par la vue depuis kintai\Core\Extension\Navigation (mêmes clés que la barre du haut).
    private const NAV_EMPLOYEE_SECTIONS       = ['planning', 'requests', 'statistics', 'account'];

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly AuthService $auth,
        private readonly AuditLogger $auditLogger,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly IcalTokenRepositoryInterface $icalTokens,
        private readonly UserNavPrefsRepositoryInterface $navPrefs,
        private readonly AvailabilityRepositoryInterface $availabilities,
        private readonly LanguageRepositoryInterface $languages,
        private readonly AvatarImageOptimizer $avatarOptimizer,
        // Optionnel pour ne pas casser les tests qui construisent le contrôleur à la main ;
        // le conteneur l'injecte toujours en production.
        private readonly ?CredentialRevoker $revoker = null,
        private readonly ?UserAnonymizer $anonymizer = null,
        private readonly ?NotificationPreferenceService $notifPrefs = null,
        private readonly ?WebPushSubscriptionRepositoryInterface $webPushSubscriptions = null,
        private readonly ?NotificationMailer $notifMailer = null,
        private readonly ?CalendarSyncRepositoryInterface $calendars = null,
        private readonly ?GoogleCalendarSettings $googleCalendar = null,
    ) {}

    /** Le conteneur l'injecte toujours ; un test qui supprime un compte doit le fournir. */
    private function anonymizer(): UserAnonymizer
    {
        return $this->anonymizer ?? throw new \LogicException('UserAnonymizer requis pour supprimer un compte.');
    }

    /**
     * Liaison Google Calendar de l'utilisateur pour l'onglet iCal : disponible seulement si l'Owner l'a activée.
     * Le jeton de rafraîchissement n'est jamais transmis à la vue.
     *
     * @return array{available: bool, connection: ?array<string, mixed>}
     */
    private function calendarPanel(int $userId): array
    {
        $connection = null;
        if ($this->calendars !== null) {
            try {
                $row = $this->calendars->findConnectionByUser($userId);
            } catch (\Throwable) {
                $row = null; // table absente : migration pas encore jouée
            }
            if ($row !== null) {
                $options = json_decode((string) ($row['options'] ?? ''), true);
                $options = is_array($options) ? $options : [];
                $connection = [
                    'status'        => (string) $row['status'],
                    'email'         => (string) ($row['account_email'] ?? ''),
                    'calendar_name' => (string) ($row['calendar_name'] ?? ''),
                    'last_sync_at'  => $row['last_sync_at'] ?? null,
                    'last_error'    => $row['last_error'] ?? null,
                    'name_mode'     => ($options['calendar_name_mode'] ?? 'instance') === 'store' ? 'store' : 'instance',
                    'store_id'      => (int) ($options['store_id'] ?? 0),
                ];
            }
        }
        return ['available' => $this->googleCalendar?->isAvailable() ?? false, 'connection' => $connection];
    }

    /** @var array<string, string> extension → type MIME (avatars) */
    private const AVATAR_MIME_TYPES = [
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** Affiche le formulaire de connexion. */
    public function showLogin(Request $request): Response
    {
        // Déjà connecté → rediriger vers le dashboard
        if ($this->auth->check()) {
            return Response::redirect($this->base() . '/');
        }

        return Response::html($this->view->render('auth.login', [
            'title'      => __('login_page'),
            'error'      => !empty($_GET['error']) && $_GET['error'] !== 'expired',
            // Formulaire validé après expiration de la session (jeton CSRF refusé, voir CsrfMiddleware).
            'expired'    => ($_GET['error'] ?? '') === 'expired',
            'login_mode' => $_GET['mode'] ?? 'code',
            // Sélecteur de langue de la page de connexion (pour la session ; voir switchLanguage()).
            'login_languages' => $this->languages->findAllActive(),
            'current_locale'  => \kintai\Core\Container::getInstance()->make(\kintai\Core\Services\TranslationService::class)->getLocale(),
        ], 'layout.guest'));
    }

    /** Traite la soumission du formulaire de connexion. */
    public function login(Request $request): Response
    {
        $mode     = $request->post('login_mode', 'email');
        $remember = $request->post('remember') === '1';

        if ($mode === 'code') {
            // Connexion par code employé + code magasin + mot de passe
            $employeeCode = trim($request->post('employee_code', ''));
            $storeCode    = trim($request->post('store_code', ''));
            $password     = $request->post('password', PasswordPolicy::DEFAULT_PASSWORD);
            $ok = $this->auth->attemptByCode($employeeCode, $storeCode, $password, $remember);
        } else {
            // Connexion classique email + mot de passe
            $email    = trim($request->post('email', ''));
            $password = $request->post('password', '');
            $ok = $this->auth->attempt($email, $password, $remember);
        }

        if ($ok) {
            $authUser = $this->auth->user();
            $userId   = $authUser ? (int) ($authUser['id'] ?? 0) : null;
            $this->auditLogger->log($request, 'auth.login', 'user', $userId, ['mode' => $mode], null, $userId);
            if ($userId !== null) {
                $this->users->save(['id' => $userId, 'last_login_at' => date('Y-m-d H:i:s')]);
            }

            // Laisser la préférence BD de l'utilisateur prendre effet (I18nMiddleware), et la retenir pour la page
            // de connexion de ce navigateur après déconnexion.
            unset($_SESSION['locale']);
            LocaleCookie::remember(is_array($authUser) ? (string) ($authUser['language'] ?? '') : null);

            if ($this->auth->isAdmin()) {
                $destination = $this->base() . '/admin/shifts/timeline';
            } elseif ($this->auth->isManager()) {
                $destination = $this->base() . '/admin/shifts/timeline';
            } else {
                $destination = $this->base() . '/employee';
            }
            return Response::redirect($destination);
        }

        // Lu par LoginThrottleMiddleware : seuls les échecs comptent dans la limitation.
        $request->setAttribute('auth_failed', true);
        $this->auditLogger->log($request, 'auth.login_failed', 'user', null, ['mode' => $mode]);
        return Response::redirect($this->base() . '/login?error=1&mode=' . urlencode($mode));
    }

    /** Bascule entre vue mobile et vue bureau (forcé en session). */
    public function switchDevice(Request $request): Response
    {
        $target = $request->post('device_view', '');
        if (in_array($target, ['mobile', 'desktop'], true)) {
            $_SESSION['device_view'] = $target;
        } else {
            unset($_SESSION['device_view']); // retour à la détection auto
        }

        // Retour à la page d'origine seulement si elle appartient au site (back_url() vérifie l'origine du Referer) :
        // l'en-tête vient du navigateur et pouvait renvoyer vers n'importe quel site.
        return Response::redirect(back_url($this->base() . '/'));
    }

    /**
     * Sélecteur de langue. Connecté : enregistrée dans le profil (et retenue pour la page de connexion). Non
     * connecté (page de connexion) : pour la session seulement.
     */
    public function switchLanguage(Request $request): Response
    {
        $locale = $request->param('locale');
        $activeCodes = array_column($this->languages->findAllActive(), 'code');
        if (!in_array($locale, $activeCodes, true)) {
            return Response::redirect(back_url($this->base() . '/'));
        }

        $user = $this->auth->user();
        if ($user) {
            $dbUser = $this->users->findById((int) $user['id']);
            if ($dbUser) {
                $dbUser['language'] = $locale;
                $this->users->save($dbUser);
                LocaleCookie::remember($locale);
            }
        } else {
            $_SESSION['locale'] = $locale;
        }

        // Retour à la page d'origine seulement si elle appartient au site (back_url() vérifie l'origine du Referer) :
        // l'en-tête vient du navigateur et pouvait renvoyer vers n'importe quel site.
        return Response::redirect(back_url($this->base() . '/'));
    }

    /** Affiche le profil de l'utilisateur. */
    public function showProfile(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId             = (int) $user['id'];
        $hasDefaultPassword = $this->auth->mustChangePassword();
        $scheme             = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host               = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base               = $this->base();

        $memberships = $this->storeUsers->findByUser($userId);
        $stores      = [];
        foreach ($memberships as $m) {
            $s = $this->stores->findById((int) $m['store_id']);
            if ($s) {
                $stores[(int) $s['id']] = $s;
            }
        }

        // Onglet courant
        // « push » : ancien nom de l'onglet, devenu « notifications » (liens enregistrés, anciennes redirections).
        $tab = (string) $request->query('tab');
        $tab = $tab === 'push' ? 'notifications' : $tab;
        $tab = in_array($tab, ['info', 'availability', 'ical', 'notifications', 'nav', 'data'], true) ? $tab : 'info';

        // Liens iCal
        $icalLinks = [];
        foreach ($memberships as $membership) {
            $storeId  = (int) $membership['store_id'];
            $store    = $stores[$storeId] ?? null;
            if (!$store) {
                continue;
            }

            $tokenRow = $this->icalTokens->findOrCreateForUserAndStore($userId, $storeId);

            $feedUrl = "{$scheme}://{$host}{$base}/ical/{$tokenRow['token']}/{$storeId}/shifts.ics";
            $icalLinks[] = [
                'store_id'   => $storeId,
                'store_name' => $store['name'] ?? '',
                'url'        => $feedUrl,
                // Ajout en un clic : webcal (Apple, Thunderbird), Google Calendar, Outlook.
                'add'        => \kintai\Core\Services\CalendarSubscriptionLinks::for($feedUrl, (string) ($store['name'] ?? 'Kintai')),
            ];
        }

        // Disponibilités (tab availability)
        $storeId  = (int) ($request->query('store_id') ?? array_key_first($stores) ?? 0);
        if (!isset($stores[$storeId]) && !empty($stores)) {
            $storeId = (int) array_key_first($stores);
        }
        $existing = [];
        if ($storeId > 0) {
            foreach ($this->availabilities->findByUserAndStore($userId, $storeId) as $a) {
                $existing[(int) $a['day_of_week']] = $a;
            }
        }

        // Un manager (ou admin) voit le vocabulaire de nav admin ; un employé simple
        // (aucun store géré) voit son propre vocabulaire, plus restreint.
        $isManagerView = $this->auth->isManager();
        $isOwner       = !empty($user['is_admin']);
        if ($isManagerView) {
            $defaultSecs = $isOwner ? self::NAV_OWNER_SECTIONS : self::NAV_MANAGER_SECTIONS;
            $bnDefault   = \kintai\Core\Extension\Navigation::MOBILE_DEFAULT_MANAGER;
        } else {
            $defaultSecs = self::NAV_EMPLOYEE_SECTIONS;
            $bnDefault   = \kintai\Core\Extension\Navigation::MOBILE_DEFAULT_EMPLOYEE;
        }

        $navHidden   = $this->navPrefs->getHidden($userId);
        $navRawOrder = $this->navPrefs->getSectionOrder($userId);
        $navSecOrder = array_values(array_unique(array_merge(
            array_intersect($navRawOrder, $defaultSecs),
            $defaultSecs
        )));

        $bnSaved   = \kintai\Core\Extension\Navigation::migrateLegacyMobileKeys($this->navPrefs->getBottomNavItems($userId));
        $bnItems   = !empty($bnSaved) ? $bnSaved : $bnDefault;

        return Response::html($this->view->render('auth.profile', [
            'title'                => __('profile'),
            'user'                 => $user,
            'has_default_password' => $hasDefaultPassword,
            'tab'                  => $tab,
            'stores'               => $stores,
            'store_id'             => $storeId,
            'existing'             => $existing,
            'ical_links'           => $icalLinks,
            'calendar'             => $this->calendarPanel($userId),
            'nav_hidden'           => $navHidden,
            'nav_section_order'    => $navSecOrder,
            'nav_default_sections' => $defaultSecs,
            'is_owner'             => $isOwner,
            'is_manager_view'      => $isManagerView,
            'bottom_nav_items'     => $bnItems,
            // Onglet Notifications : grille type × canal et heures silencieuses.
            'notif_grid'           => $tab === 'notifications' ? ($this->notifPrefs?->grid($userId) ?? []) : [],
            'quiet_hours'          => $tab === 'notifications' ? $this->notifPrefs?->quietHours($userId) : null,
            // Colonne E-mail : sans e-mail réglé sur l'instance (ou sans URL publique), le choix ne servirait à rien.
            'email_available'      => $tab === 'notifications' && ($this->notifMailer?->isAvailable() ?? false),
            // Sections des bundles actifs dans « Mes informations » (contrat 1.12), ex. l'annuaire.
            'profile_sections'     => ProfileSections::current()->render($user, $this->view),
        ], 'layout.app'));
    }

    /** Enregistre la grille des préférences de notification et les heures silencieuses (onglet Notifications). */
    public function saveNotificationPreferences(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }
        if ($this->notifPrefs === null) {
            throw new \LogicException('NotificationPreferenceService requis pour enregistrer les préférences.');
        }

        $userId = (int) $user['id'];
        $back   = $this->base() . '/profile?tab=notifications';
        $list   = static fn(mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        $quietOk = $this->notifPrefs->saveQuietHours(
            $userId,
            $request->postFlag('quiet_enabled', 0) === 1,
            (string) $request->post('quiet_start', ''),
            (string) $request->post('quiet_end', ''),
        );
        if (!$quietOk) {
            return Response::redirect($back . '&error=quiet_hours_invalid');
        }
        // shown[] : les lignes que le formulaire affichait (seules leurs cases sont enregistrées). Absent : tous les types actifs.
        $shown = $request->post('shown') !== null ? $list($request->post('shown')) : null;
        $this->notifPrefs->saveGrid($userId, $list($request->post('push', [])), $list($request->post('email', [])), $shown);

        return Response::redirect($back . '&success=saved');
    }

    /** Met à jour le profil de l'utilisateur. */
    public function updateProfile(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId      = (int) $user['id'];
        $activeCodes = array_column($this->languages->findAllActive(), 'code');
        // Champ absent : la langue actuelle est conservée (voir le commentaire plus bas). Vide : le compte suit la
        // langue de l'instance ; une langue inconnue ou désactivée revient aussi à la langue de l'instance.
        $language    = (string) $request->post('language', (string) ($user['language'] ?? ''));
        if (!in_array($language, $activeCodes, true)) {
            $language = '';
        }

        // Récupérer l'utilisateur complet depuis la DB pour être sûr de ne rien perdre
        $dbUser = $this->users->findById($userId);
        if ($dbUser) {
            $oldUser = $dbUser;
            // Pas de catch ici : la colonne "language" existe depuis la toute première
            // migration de création de users (elle ne peut pas manquer si la table existe),
            // et avaler l'échec masquerait un vrai problème derrière un "?success=1" trompeur
            // — l'utilisateur croirait sa préférence enregistrée alors qu'elle ne l'est pas.
            $dbUser['language']            = $language;
            // Un champ absent de la requête garde sa valeur (audit du 13/10/2026, M12) : une requête partielle
            // vidait téléphone et adresse.
            foreach (['phone', 'mobile_phone', 'postal_code', 'address', 'bio', 'skills', 'languages_spoken', 'hobbies'] as $field) {
                if ($request->hasPost($field)) {
                    $dbUser[$field] = trim((string) $request->post($field, ''));
                }
            }
            // Cases des sections de bundles affichées (contrat 1.12, ex. l'annuaire) : seules celles-ci sont
            // enregistrées ; les autres, dont celles d'un bundle désactivé, gardent leur valeur.
            foreach (ProfileSections::current()->flags() as $flag) {
                $dbUser[$flag] = $request->postFlag($flag, $dbUser[$flag] ?? 0);
            }
            $this->users->save($dbUser);

            LocaleCookie::remember($language);
            $this->auditLogger->logUpdate($request, 'user.update_profile', 'user', $userId, $oldUser, $dbUser, [], null, $userId);
        }

        return Response::redirect($this->base() . '/profile?success=1');
    }

    /** Met à jour la photo de profil de l'utilisateur connecté. */
    public function uploadAvatar(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId = (int) $user['id'];
        $file   = $request->file('avatar');
        if ($file === null || !is_uploaded_file($file['tmp_name'])) {
            return Response::redirect($this->base() . '/profile?tab=info&error=avatar_invalid');
        }

        $dbUser = $this->users->findById($userId);
        if ($dbUser === null) {
            return Response::redirect($this->base() . '/profile?tab=info&error=error_generic');
        }

        $avatarDir = BASE_PATH . '/storage/uploads/avatars/';
        if (!is_dir($avatarDir)) {
            mkdir($avatarDir, 0775, true);
        }

        $compressed = $this->avatarOptimizer->optimize($file['tmp_name'], $avatarDir . 'user_' . $userId);
        if ($compressed === null) {
            return Response::redirect($this->base() . '/profile?tab=info&error=avatar_invalid');
        }

        // Un ancien avatar dans une extension différente (ex. .png → .jpg après
        // recompression) resterait orphelin sur le disque sans ce nettoyage.
        $oldFilename = $dbUser['avatar_path'] ?? null;
        if ($oldFilename !== null) {
            $oldPath = $avatarDir . basename((string) $oldFilename);
            if (is_file($oldPath) && $oldPath !== $compressed['path']) {
                @unlink($oldPath);
            }
        }

        $oldUser = $dbUser;
        $dbUser['avatar_path'] = basename($compressed['path']);
        $this->users->save($dbUser);

        $this->auditLogger->logUpdate($request, 'user.avatar_updated', 'user', $userId, $oldUser, $dbUser, [], null, $userId);

        return Response::redirect($this->base() . '/profile?tab=info&success=avatar_saved');
    }

    /** Supprime la photo de profil de l'utilisateur connecté. */
    public function removeAvatar(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId = (int) $user['id'];
        $dbUser = $this->users->findById($userId);
        if ($dbUser === null || empty($dbUser['avatar_path'])) {
            return Response::redirect($this->base() . '/profile?tab=info');
        }

        $path = BASE_PATH . '/storage/uploads/avatars/' . basename((string) $dbUser['avatar_path']);
        if (is_file($path)) {
            @unlink($path);
        }

        $oldUser = $dbUser;
        $dbUser['avatar_path'] = null;
        $this->users->save($dbUser);

        $this->auditLogger->logUpdate($request, 'user.avatar_removed', 'user', $userId, $oldUser, $dbUser, [], null, $userId);

        return Response::redirect($this->base() . '/profile?tab=info&success=avatar_removed');
    }

    /**
     * Sert la photo de profil d'un utilisateur (visible par tout utilisateur
     * connecté — même logique d'ouverture que le nom/la couleur d'identification,
     * déjà visibles à l'échelle de l'organisation dans les plannings partagés).
     */
    public function avatar(Request $request): Response
    {
        $targetId = (int) $request->param('user_id');
        $target   = $this->users->findById($targetId);
        $filename = $target['avatar_path'] ?? null;
        if ($target === null || $filename === null) {
            throw new NotFoundException(__('error_file_not_found'));
        }

        $path = BASE_PATH . '/storage/uploads/avatars/' . basename((string) $filename);
        if (!is_file($path)) {
            throw new NotFoundException(__('error_file_not_found'));
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::AVATAR_MIME_TYPES[$ext] ?? 'application/octet-stream';

        return Response::fileStream($path, $mime, basename($path))
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($path) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }

    /** Change le mot de passe de l'utilisateur. */
    public function saveProfilePassword(Request $request): Response
    {
        $user    = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId   = (int) $user['id'];
        $current  = $request->post('current_password', '');
        $newPass  = $request->post('new_password', '');
        $confirm  = $request->post('confirm_password', '');

        if ($newPass === '' || $newPass !== $confirm) {
            return Response::redirect($this->base() . '/profile?tab=info&error=password_mismatch');
        }

        if (!PasswordPolicy::isLongEnough($newPass)) {
            return Response::redirect($this->base() . '/profile?tab=info&error=password_too_short');
        }

        $dbUser = $this->users->findById($userId);
        if ($dbUser === null) {
            return Response::redirect($this->base() . '/profile?tab=info&error=error_generic');
        }

        // Toujours vérifier : un hash vide fait échouer password_verify(), donc refuse l'action.
        $storedHash = (string) ($dbUser['password_hash'] ?? '');
        if (!password_verify($current, $storedHash)) {
            return Response::redirect($this->base() . '/profile?tab=info&error=current_password_wrong');
        }

        $oldUser = $dbUser;
        $dbUser['password_hash'] = \kintai\Core\Auth\PasswordHasher::hash($newPass);
        try {
            $this->users->save($dbUser);
            // La session courante reste valide ; toutes les autres (autres appareils, cookie
            // « rester connecté », jetons d'API) sont révoquées : c'est le but d'un changement
            // de mot de passe après une compromission.
            $this->auth->refreshSessionAfterPasswordChange($dbUser);
            $this->revoker?->revokeAllFor($userId);
            $this->auditLogger->logUpdate($request, 'user.change_password', 'user', $userId, $oldUser, $dbUser, [], null, $userId);
        } catch (\Throwable) {
            return Response::redirect($this->base() . '/profile?tab=info&error=error_generic');
        }

        return Response::redirect($this->base() . '/profile?tab=info&success=password_changed');
    }

    /** Exporte les données personnelles de l'utilisateur (RGPD portabilité). */
    public function exportData(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId = (int) $user['id'];

        $data = [
            'personal_info' => [
                'first_name'        => $user['first_name'] ?? null,
                'last_name'         => $user['last_name'] ?? null,
                'email'             => $user['email'] ?? null,
                'phone'             => $user['phone'] ?? null,
                'mobile_phone'      => $user['mobile_phone'] ?? null,
                'postal_code'       => $user['postal_code'] ?? null,
                'address'           => $user['address'] ?? null,
                'language'          => $user['language'] ?? null,
                'bio'               => $user['bio'] ?? null,
                'skills'            => $user['skills'] ?? null,
                'languages_spoken'  => $user['languages_spoken'] ?? null,
                'hobbies'           => $user['hobbies'] ?? null,
            ],
            'memberships' => $this->storeUsers->findByUser($userId),
            'availabilities' => $this->availabilities->findByUser($userId),
        ];

        $this->auditLogger->log($request, 'user.data_export', 'user', $userId, [], null, $userId);

        // Response n'a pas de constructeur public : « new Response($json, 200, [...]) » renvoyait une réponse vide.
        return Response::jsonDownload($data, 'kintai-data-' . $userId . '.json');
    }

    /** Supprime le compte de l'utilisateur connecté (auto-service RGPD). */
    public function deleteAccount(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId   = (int) $user['id'];
        $password = (string) $request->post('password', '');

        // Un Owner ne supprime pas son propre compte : la suppression anonymise et désactive le compte, ce qui
        // pourrait laisser l'instance sans administrateur. Il doit d'abord céder le rôle à un autre Owner.
        if ($this->auth->isAdmin()) {
            return Response::redirect($this->base() . '/profile?tab=data&error=owner_cannot_delete');
        }

        // Toujours vérifier : un hash vide fait échouer password_verify(), donc refuse l'action.
        $storedHash = (string) ($user['password_hash'] ?? '');
        if (!password_verify($password, $storedHash)) {
            return Response::redirect($this->base() . '/profile?tab=data&error=current_password_wrong');
        }

        // Anonymisation (données personnelles, avatar, rôles, jetons) : même traitement que la suppression
        // par un admin, l'historique reste rattaché au compte.
        $this->anonymizer()->anonymize($userId);

        $this->auditLogger->log($request, 'user.self_deleted', 'user', $userId, [], null, $userId);

        $this->auth->logout();
        return Response::redirect($this->base() . '/login?deleted=1');
    }

    /** Déconnecte l'utilisateur et redirige vers /login. */
    public function logout(Request $request): Response
    {
        // Capturer l'utilisateur avant la déconnexion
        $authUser = $this->auth->user();
        $userId   = $authUser ? (int) ($authUser['id'] ?? 0) : null;

        $this->auth->logout();

        // Le navigateur qui se déconnecte ne doit plus recevoir les notifications de ce compte : son abonnement web
        // push (adresse envoyée avec le formulaire par push.js) est supprimé. Seul celui de ce compte l'est.
        $pushEndpoint = trim((string) $request->post('push_endpoint', ''));
        if ($pushEndpoint !== '' && $userId !== null && $this->webPushSubscriptions !== null) {
            try {
                $this->webPushSubscriptions->deleteByEndpoint($pushEndpoint, $userId);
            } catch (\Throwable) {
                // La déconnexion prime sur le nettoyage de l'abonnement.
            }
        }

        $this->auditLogger->log($request, 'auth.logout', 'user', $userId, [], null, $userId);
        return Response::redirect($this->base() . '/login');
    }


}
