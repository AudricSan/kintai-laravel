<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Service d'authentification basé sur les sessions PHP natives.
 * Stocke uniquement l'ID utilisateur en session ; recharge depuis la DB à chaque requête.
 *
 * isAdmin()/managedStoreIds() sont calculés à partir de role_assignments (RBAC
 * dynamique, task/mermission.md) plutôt que des colonnes historiques
 * users.is_admin/store_user.role. user() applique ce calcul directement sur
 * la clé 'is_admin' du tableau retourné : c'est le seul point de pontage
 * nécessaire pour que tout le reste de l'app (contrôleurs qui lisent
 * $request->getAttribute('auth_user')['is_admin'] directement plutôt que via
 * ce service) continue de fonctionner sans modification.
 */
final class AuthService
{
    private const SESSION_KEY = 'auth_user_id';
    // Empreinte du hash de mot de passe au moment de la connexion : si le mot de passe change
    // (lui-même, réinitialisation, admin), toute session ouverte avant devient invalide.
    private const SESSION_PW_FINGERPRINT = 'auth_pw_fp';
    /** État « mot de passe à changer » de la session : ['fp' => empreinte du hash, 'weak' => bool]. */
    private const SESSION_PW_WEAK = 'auth_pw_weak';
    /** Posé à chaque nouvelle session : la fenêtre de rappel du mot de passe s'affiche une fois par connexion. */
    private const SESSION_PW_REMINDER = 'auth_pw_reminder_pending';
    private const REMEMBER_COOKIE = 'kintai_remember';
    // Coût bcrypt des mots de passe créés par l'app (voir AdminUserController/PasswordResetService).
    // Même coût que PasswordHasher : le calcul factice doit durer autant qu'une vraie vérification.
    private const TIMING_COST = PasswordHasher::COST;
    private const REMEMBER_LIFETIME_DAYS = 30;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly StoreRepositoryInterface $stores,
        private readonly RoleRepositoryInterface $roles,
        private readonly RoleAssignmentRepositoryInterface $roleAssignments,
        private readonly RememberTokenRepositoryInterface $rememberTokens,
    ) {}

    /**
     * Tente de connecter un utilisateur avec email + mot de passe.
     * Retourne true si les identifiants sont valides et l'utilisateur actif.
     */
    public function attempt(string $email, string $password, bool $remember = false): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $this->burnPasswordCheck($password);
            return false;
        }

        if (empty($user['is_active']) || !empty($user['deleted_at'])) {
            $this->burnPasswordCheck($password);
            return false;
        }

        if (!password_verify($password, $user['password_hash'] ?? '')) {
            return false;
        }

        session_regenerate_id(true);
        $this->bindSession($user);
        $this->rememberPasswordStrength($user, $password);
        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        }
        return true;
    }

    /**
     * Tente de connecter un employé avec code_employé + code_magasin + mot de passe.
     * Le mot de passe par défaut est "0000".
     */
    public function attemptByCode(string $employeeCode, string $storeCode, string $password, bool $remember = false): bool
    {
        $store = $this->stores->findByCode(strtoupper(trim($storeCode)));
        if ($store === null) {
            $this->burnPasswordCheck($password);
            return false;
        }

        $user = $this->users->findByEmployeeCode(trim($employeeCode));
        if ($user === null) {
            $this->burnPasswordCheck($password);
            return false;
        }

        if (empty($user['is_active']) || !empty($user['deleted_at'])) {
            $this->burnPasswordCheck($password);
            return false;
        }

        // Vérifier que l'utilisateur est membre de ce store
        $membership = $this->storeUsers->findMembership((int) $store['id'], (int) $user['id']);
        if ($membership === null) {
            $this->burnPasswordCheck($password);
            return false;
        }

        if (!password_verify($password, $user['password_hash'] ?? '')) {
            return false;
        }

        session_regenerate_id(true);
        $this->bindSession($user);
        $this->rememberPasswordStrength($user, $password);
        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        }
        return true;
    }

    /**
     * Relit le cookie "rester connecté" (posé par issueRememberToken) et reconnecte
     * l'utilisateur s'il est valide. Fait tourner le token à chaque utilisation réussie
     * (ancien sélecteur supprimé, nouveau émis) : limite la fenêtre de rejeu si le cookie
     * a fuité, sans jamais forcer l'utilisateur à se reconnecter tant qu'il revient avant
     * expiration. $cookieValue permet de s'affranchir de $_COOKIE dans les tests.
     */
    public function attemptViaRememberCookie(?string $cookieValue = null): bool
    {
        $cookieValue ??= $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!$cookieValue || !str_contains($cookieValue, '.')) {
            return false;
        }

        [$selector, $validator] = explode('.', $cookieValue, 2);
        $token = $this->rememberTokens->findBySelector($selector);
        if ($token === null) {
            $this->clearRememberCookie();
            return false;
        }

        // Sélecteur valide mais mauvais validateur, ou token expiré : le cookie est
        // potentiellement volé/rejoué — on révoque par précaution plutôt que d'échouer
        // silencieusement en le laissant réutilisable.
        if (
            strtotime((string) $token['expires_at']) < time()
            || !hash_equals((string) $token['validator_hash'], hash('sha256', $validator))
        ) {
            $this->rememberTokens->deleteBySelector($selector);
            $this->clearRememberCookie();
            return false;
        }

        $user = $this->users->findById((int) $token['user_id']);
        if ($user === null || empty($user['is_active']) || !empty($user['deleted_at'])) {
            $this->rememberTokens->deleteBySelector($selector);
            $this->clearRememberCookie();
            return false;
        }

        $this->rememberTokens->deleteBySelector($selector);

        session_regenerate_id(true);
        $this->bindSession($user);
        $this->issueRememberToken((int) $user['id']);

        return true;
    }

    /**
     * Identifiant du compte de la session, sans aucune vérification (compte actif, mot de passe inchangé) : réservé
     * aux usages qui ne donnent aucun droit, comme le choix de la langue d'affichage (I18nMiddleware).
     */
    public static function sessionUserId(): ?int
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Retourne l'utilisateur connecté (rechargé depuis la DB), ou null.
     * 'is_admin' reflète le calcul RBAC effectif (rôle système en portée
     * globale), pas la colonne brute users.is_admin.
     */
    public function user(): ?array
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id === null) {
            return null;
        }
        $user = $this->users->findById((int) $id);
        // Une session ouverte ne doit jamais survivre à la désactivation ou à la suppression
        // du compte : seules la connexion et le cookie « rester connecté » le vérifiaient.
        if ($user === null || !($user['is_active'] ?? 1) || !empty($user['deleted_at'])) {
            $this->dropSession();
            return null;
        }
        // Le mot de passe a changé depuis l'ouverture de cette session (par l'utilisateur, par
        // réinitialisation ou par un admin) : elle est révoquée, y compris sur les autres appareils.
        // Une session ouverte avant l'introduction de l'empreinte l'adopte à sa première requête.
        $stored = $_SESSION[self::SESSION_PW_FINGERPRINT] ?? null;
        if (!is_string($stored)) {
            $_SESSION[self::SESSION_PW_FINGERPRINT] = $this->fingerprint($user);
        } elseif (!hash_equals($stored, $this->fingerprint($user))) {
            $this->dropSession();
            return null;
        }
        $user['is_admin'] = $this->hasOwnerRole((int) $user['id']) ? 1 : 0;
        return $user;
    }

    /** L'utilisateur est-il connecté (session valide, compte toujours actif) ? */
    public function check(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]) && $this->user() !== null;
    }

    /**
     * Le mot de passe de l'utilisateur connecté devrait-il être changé ? C'est le cas du mot de passe par défaut
     * « 0000 » (attribué à la création d'un compte ou à sa réinitialisation par un admin) et de tout mot de
     * passe sous PasswordPolicy::MIN_LENGTH. Sur le web, ce n'est qu'un rappel (bandeau + fenêtre à la connexion,
     * PasswordReminderMiddleware) ; l'API, elle, refuse de délivrer un jeton.
     *
     * Le second cas n'est connu qu'à la connexion par mot de passe (seul moment où le clair est lisible) ;
     * pour une session ouverte autrement (cookie « rester connecté », session antérieure à ce contrôle),
     * seul « 0000 » peut être détecté, une fois, en comparant au hash.
     */
    public function mustChangePassword(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $fingerprint = $this->fingerprint($user);
        $state = $_SESSION[self::SESSION_PW_WEAK] ?? null;
        if (!is_array($state) || !hash_equals((string) ($state['fp'] ?? ''), $fingerprint)) {
            $state = [
                'fp'   => $fingerprint,
                'weak' => password_verify(PasswordPolicy::DEFAULT_PASSWORD, (string) ($user['password_hash'] ?? '')),
            ];
            $_SESSION[self::SESSION_PW_WEAK] = $state;
        }

        return (bool) $state['weak'];
    }

    /**
     * À appeler après que l'utilisateur CONNECTÉ a changé son propre mot de passe : la session
     * courante reste valide (nouvelle empreinte), toutes les autres sont invalidées.
     */
    public function refreshSessionAfterPasswordChange(array $user): void
    {
        $_SESSION[self::SESSION_PW_FINGERPRINT] = $this->fingerprint($user);
        // Le nouveau mot de passe a passé PasswordPolicy : il ne doit plus être réclamé.
        $_SESSION[self::SESSION_PW_WEAK] = ['fp' => $this->fingerprint($user), 'weak' => false];
    }

    /** Mémorise, à la connexion, si le mot de passe saisi est trop court (clair disponible uniquement ici). */
    private function rememberPasswordStrength(array $user, string $password): void
    {
        $_SESSION[self::SESSION_PW_WEAK] = [
            'fp'   => $this->fingerprint($user),
            'weak' => !PasswordPolicy::isLongEnough($password),
        ];
    }

    private function bindSession(array $user): void
    {
        $_SESSION[self::SESSION_KEY] = $user['id'];
        $_SESSION[self::SESSION_PW_FINGERPRINT] = $this->fingerprint($user);
        $_SESSION[self::SESSION_PW_REMINDER] = true;
    }

    private function dropSession(): void
    {
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION[self::SESSION_PW_FINGERPRINT],
            $_SESSION[self::SESSION_PW_WEAK],
            $_SESSION[self::SESSION_PW_REMINDER],
        );
    }

    /**
     * Faut-il afficher la fenêtre de rappel du mot de passe ? Vrai une seule fois par connexion (connexion par mot
     * de passe ou par cookie « rester connecté »), et seulement si le mot de passe devrait être changé. Le signal
     * est consommé dans tous les cas : la fenêtre ne réapparaît qu'à la connexion suivante.
     */
    public function consumePasswordReminder(): bool
    {
        $pending = !empty($_SESSION[self::SESSION_PW_REMINDER]);
        unset($_SESSION[self::SESSION_PW_REMINDER]);

        return $pending && $this->mustChangePassword();
    }

    private function fingerprint(array $user): string
    {
        return hash('sha256', (string) ($user['password_hash'] ?? ''));
    }

    /** L'utilisateur connecté est-il admin global (rôle système, portée globale) ? */
    public function isAdmin(): bool
    {
        return !empty($this->user()['is_admin']);
    }

    /**
     * Retourne les IDs des stores que l'utilisateur gère (rôle accordant au
     * moins une permission sur ce store). Retourne un tableau vide si admin
     * global (pas de restriction) ou non connecté.
     * @return int[]
     */
    public function managedStoreIds(): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }
        // Admin global → pas de restriction, on retourne [] (signifie « tous »)
        if (!empty($user['is_admin'])) {
            return [];
        }

        $storeIds = [];
        foreach ($this->roleAssignments->findByUser((int) $user['id']) as $assignment) {
            if ($assignment['scope_type'] !== 'store' || $assignment['scope_id'] === null) {
                continue;
            }
            if ($this->roleIsManagerType((int) $assignment['role_id'])) {
                $storeIds[] = (int) $assignment['scope_id'];
            }
        }
        return array_values(array_unique($storeIds));
    }

    /** L'utilisateur est-il gestionnaire d'au moins un store (ou admin global) ? */
    public function isManager(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return !empty($this->managedStoreIds());
    }

    /** Déconnecte l'utilisateur courant (et révoque le "rester connecté" s'il y en a un). */
    public function logout(): void
    {
        $this->dropSession();
        $this->revokeRememberCookie();
    }

    /**
     * Émet un nouveau token "rester connecté" (30 jours) : sélecteur en clair (indexé,
     * sert à la recherche) + hash SHA-256 du validateur (jamais stocké en clair) — schéma
     * repris de celui déjà utilisé pour api_tokens, adapté en sélecteur/validateur pour
     * qu'une lecture de la table seule ne permette pas de rejouer un cookie volé côté DB.
     */
    private function issueRememberToken(int $userId): void
    {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + self::REMEMBER_LIFETIME_DAYS * 86400;

        $this->rememberTokens->create([
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at'     => date('Y-m-d H:i:s', $expires),
        ]);

        $this->setRememberCookie($selector . '.' . $validator, $expires);
    }

    /** Révoque le token en base associé au cookie courant (s'il existe) et efface le cookie. */
    private function revokeRememberCookie(): void
    {
        $cookieValue = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if ($cookieValue && str_contains($cookieValue, '.')) {
            [$selector] = explode('.', $cookieValue, 2);
            $this->rememberTokens->deleteBySelector($selector);
        }
        $this->clearRememberCookie();
    }

    private function setRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    /**
     * Dépense un calcul bcrypt pour rien. Sans cela, un e-mail inconnu, un compte inactif ou un magasin
     * inexistant répondaient bien plus vite qu'un vrai compte avec un mauvais mot de passe (qui, lui,
     * paie un password_verify) : cette différence de durée permettait de tester l'existence d'un compte.
     */
    private function burnPasswordCheck(string $password): void
    {
        password_hash($password, PASSWORD_BCRYPT, ['cost' => self::TIMING_COST]);
    }

    private function hasOwnerRole(int $userId): bool
    {
        foreach ($this->roleAssignments->findByUser($userId) as $assignment) {
            if ($assignment['scope_type'] !== 'global') {
                continue;
            }
            $role = $this->roles->findById((int) $assignment['role_id']);
            if ($role !== null && !empty($role['is_system'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vrai si ce rôle doit afficher la navigation manager (dashboard admin, menus de
     * gestion) — piloté par le champ explicite roles.is_manager, coché par l'Owner sur
     * /admin/roles, INDÉPENDANT des permissions RBAC que le rôle accorde par ailleurs.
     *
     * Historique : cette méthode s'appelait roleGrantsAnyPermission() et se basait sur
     * "le rôle accorde-t-il au moins une permission, peu importe laquelle". Ce critère a
     * posé deux problèmes symétriques au fil du temps :
     * - Entre le 06/08/2026 et le 12/09/2026, une variante excluait les rôles n'accordant
     *   QUE des permissions .view, ce qui bloquait à tort tout rôle "lecture seule" légitime
     *   (ex. un rôle "consulter les rapports photos", n'accordant que photos.view) — il ne
     *   pouvait jamais atteindre /admin/photos, alors même que sa permission l'y autorisait.
     * - À partir du 16/09/2026, le problème inverse est apparu : accorder à un rôle "Employé"
     *   une permission purement en libre-service (ex. photos.create, pour lui permettre de
     *   poster sa propre photo de store) le faisait basculer en navigation manager complète,
     *   alors qu'aucune des deux notions n'a de raison d'être liée — un rôle peut légitimement
     *   accorder une permission sans jamais devoir afficher le tableau de bord admin.
     * roles.is_manager résout les deux à la fois : la navigation dépend uniquement de ce
     * champ, jamais du contenu des permissions accordées. PermissionMiddleware reste la
     * barrière fine qui limite l'accès route par route à la permission réellement requise,
     * complètement indépendante de ce champ.
     */
    private function roleIsManagerType(int $roleId): bool
    {
        $role = $this->roles->findById($roleId);
        if ($role === null) {
            return false;
        }
        return !empty($role['is_system']) || !empty($role['is_manager']);
    }
}
