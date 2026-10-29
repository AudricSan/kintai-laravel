<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\RequestMemo;
use App\Models\User;
use App\Repositories\DatabaseUserRepository;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Service d'authentification de l'application, au-dessus du garde de session de Laravel (Auth::guard()) : la connexion,
 * la déconnexion, le « rester connecté » (cookie recaller et colonne users.remember_token) et la révocation d'une
 * session dont le mot de passe a changé (AuthenticateSession) sont ceux de Laravel.
 *
 * Reste propre à l'application : la connexion par code employé + code magasin, le calcul RBAC de 'is_admin', les
 * magasins gérés, et le rappel de mot de passe faible. Les contrôleurs lisent l'utilisateur sous forme de tableau
 * (user()) : recalculé depuis la base à chaque requête.
 *
 * isAdmin()/managedStoreIds() sont calculés à partir de role_assignments (RBAC dynamique) plutôt que des colonnes
 * historiques users.is_admin/store_user.role. user() applique ce calcul directement sur la clé 'is_admin' du tableau
 * retourné : c'est le seul point de pontage nécessaire pour que tout le reste de l'app (contrôleurs qui lisent
 * $request->attributes->get('auth_user')['is_admin'] directement plutôt que via ce service) continue de fonctionner.
 */
final class AuthService
{
    /** État « mot de passe à changer » de la session : ['fp' => empreinte du hash, 'weak' => bool]. */
    private const SESSION_PW_WEAK = 'auth_pw_weak';
    /** Posé à chaque nouvelle session : la fenêtre de rappel du mot de passe s'affiche une fois par connexion. */
    private const SESSION_PW_REMINDER = 'auth_pw_reminder_pending';
    // Coût bcrypt des mots de passe créés par l'app (voir AdminUserController/PasswordResetService).
    // Même coût que PasswordHasher : le calcul factice doit durer autant qu'une vraie vérification.
    private const TIMING_COST = PasswordHasher::COST;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly StoreRepositoryInterface $stores,
        private readonly RoleRepositoryInterface $roles,
        private readonly RoleAssignmentRepositoryInterface $roleAssignments,
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

        $this->login($user, $password, $remember);
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

        $this->login($user, $password, $remember);
        return true;
    }

    /** Ouvre la session de Laravel pour ce compte (nouvel identifiant de session, cookie « rester connecté » au besoin). */
    private function login(array $user, string $password, bool $remember): void
    {
        // Le compte vient d'être lu par le dépôt : pas de seconde lecture pour le modèle du garde.
        Auth::guard()->login((new User())->newFromBuilder($user), $remember);
        $this->rememberPasswordStrength($user, $password);
    }

    /** La fenêtre de rappel du mot de passe s'affichera une fois pour cette connexion (événement Login de Laravel). */
    public function flagPasswordReminder(): void
    {
        session()->put(self::SESSION_PW_REMINDER, true);
    }
    /**
     * Identifiant du compte de la session, sans aucune vérification (compte actif, mot de passe inchangé) : réservé
     * aux usages qui ne donnent aucun droit, comme le choix de la langue d'affichage (I18nMiddleware).
     */
    public static function sessionUserId(): ?int
    {
        $id = session()->get(Auth::guard()->getName());
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Retourne l'utilisateur connecté (rechargé depuis la DB), ou null.
     * 'is_admin' reflète le calcul RBAC effectif (rôle système en portée
     * globale), pas la colonne brute users.is_admin.
     */
    public function user(): ?array
    {
        $id = self::sessionUserId();
        if ($id === null) {
            return null;
        }
        // Relu à chaque appel (middlewares, contrôleur, vues) : une fois par requête, voir RequestMemo. Seulement avec le
        // dépôt de la base, dont toute écriture vide cette mémoire : une désactivation ou un changement de mot de passe
        // se voit tout de suite.
        $user = $this->users instanceof DatabaseUserRepository
            ? RequestMemo::remember('auth.user.' . (int) $id, fn(): ?array => $this->users->findById((int) $id))
            : $this->users->findById((int) $id);
        // Une session ouverte ne doit jamais survivre à la désactivation ou à la suppression
        // du compte : seules la connexion et le cookie « rester connecté » le vérifiaient.
        if ($user === null || !($user['is_active'] ?? 1) || !empty($user['deleted_at'])) {
            $this->logout();
            return null;
        }
        $user['is_admin'] = $this->hasOwnerRole((int) $user['id']) ? 1 : 0;
        return $user;
    }

    /** L'utilisateur est-il connecté (session valide, compte toujours actif) ? */
    public function check(): bool
    {
        return self::sessionUserId() !== null && $this->user() !== null;
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
        $state = session()->get(self::SESSION_PW_WEAK);
        if (!is_array($state) || !hash_equals((string) ($state['fp'] ?? ''), $fingerprint)) {
            $state = [
                'fp'   => $fingerprint,
                'weak' => password_verify(PasswordPolicy::DEFAULT_PASSWORD, (string) ($user['password_hash'] ?? '')),
            ];
            session()->put(self::SESSION_PW_WEAK, $state);
        }

        return (bool) $state['weak'];
    }

    /**
     * À appeler après que l'utilisateur CONNECTÉ a changé son propre mot de passe : la session
     * courante reste valide (nouvelle empreinte), toutes les autres sont invalidées.
     */
    public function refreshSessionAfterPasswordChange(array $user): void
    {
        // Empreinte de session de Laravel (AuthenticateSession) : sans elle, la session courante serait révoquée aussi.
        session()->put('password_hash_' . Auth::getDefaultDriver(), Auth::guard()->hashPasswordForCookie((string) ($user['password_hash'] ?? '')));
        // Le nouveau mot de passe a passé PasswordPolicy : il ne doit plus être réclamé.
        session()->put(self::SESSION_PW_WEAK, ['fp' => $this->fingerprint($user), 'weak' => false]);
    }

    /** Mémorise, à la connexion, si le mot de passe saisi est trop court (clair disponible uniquement ici). */
    private function rememberPasswordStrength(array $user, string $password): void
    {
        session()->put(self::SESSION_PW_WEAK, [
            'fp'   => $this->fingerprint($user),
            'weak' => !PasswordPolicy::isLongEnough($password),
        ]);
    }

    /**
     * Faut-il afficher la fenêtre de rappel du mot de passe ? Vrai une seule fois par connexion (connexion par mot
     * de passe ou par cookie « rester connecté »), et seulement si le mot de passe devrait être changé. Le signal
     * est consommé dans tous les cas : la fenêtre ne réapparaît qu'à la connexion suivante.
     */
    public function consumePasswordReminder(): bool
    {
        $pending = !empty(session()->pull(self::SESSION_PW_REMINDER));

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

    /** Déconnecte l'utilisateur courant : session fermée, jeton « rester connecté » renouvelé et cookie effacé. */
    public function logout(): void
    {
        Auth::guard()->logout();
        session()->forget([self::SESSION_PW_WEAK, self::SESSION_PW_REMINDER, 'password_hash_' . Auth::getDefaultDriver()]);
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
