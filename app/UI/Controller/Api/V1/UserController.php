<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Contract\Event\EmployeeCreated;
use kintai\Core\Api\Paginator;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PasswordHasher;
use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Extension\Events;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\UserDisplayName;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;

final class UserController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
        private readonly PlanLimitService $planLimits,
        private readonly PermissionService $permissions,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly OwnerGuard $ownerGuard,
        // Optionnel pour les tests qui construisent le contrôleur à la main.
        private readonly ?CredentialRevoker $revoker = null,
        private readonly ?UserAnonymizer $anonymizer = null,
        private readonly ?Events $events = null,
    ) {}

    /** Le conteneur l'injecte toujours ; un test qui supprime un compte doit le fournir. */
    private function anonymizer(): UserAnonymizer
    {
        return $this->anonymizer ?? throw new \LogicException('UserAnonymizer requis pour supprimer un compte.');
    }

    /** GET /api/v1/users?page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $authUser = $this->authUser($request);
        // Comptes supprimés (anonymisés) : conservés pour l'historique, plus listés.
        $items    = array_values(array_filter($this->users->findAll(), fn(array $u): bool => empty($u['deleted_at'])));

        // Hors portée illimitée, seuls les membres des magasins gérés (et soi-même) sont listés.
        if (!$this->permissions->hasUnrestrictedGrant($authUser, 'employees.view')) {
            $visible = [(int) ($authUser['id'] ?? 0) => true];
            foreach ($this->permissions->scopedStoreIds((int) ($authUser['id'] ?? 0), 'employees.view') as $storeId) {
                foreach ($this->storeUsers->findByStore($storeId) as $member) {
                    $visible[(int) $member['user_id']] = true;
                }
            }
            $items = array_values(array_filter($items, fn(array $u): bool => isset($visible[(int) $u['id']])));
        }

        return Response::json(Paginator::paginate(array_map([$this, 'withoutSecrets'], $items), $page, $limit));
    }

    /** GET /api/v1/users/{id} */
    public function show(Request $request): Response
    {
        $user = $this->requireUser((int) $request->param('id'));
        $this->permissions->requireUserAccess($this->authUser($request), 'employees.view', (int) $user['id'], $this->storeIdsOf((int) $user['id']));
        return Response::json($this->withoutSecrets($user));
    }

    /** POST /api/v1/users — `password` (en clair) facultatif ; sans lui, mot de passe par défaut à changer. */
    public function store(Request $request): Response
    {
        $this->planLimits->assertCanCreateEmployee();

        $body = $request->json() ?? [];
        $data = $this->normalizeUser(PayloadValidator::validate($body, ApiPayloadRules::user()));
        $this->assertIdentityAvailable($data, 0);
        $data['password_hash'] = $this->hashFromPayload($body) ?? PasswordHasher::hash(PasswordPolicy::DEFAULT_PASSWORD);

        $saved = $this->users->save($data);
        if (!empty($saved['id'])) {
            // Contrat 1.13 : les bundles qui écoutent l'apprennent (sans magasin : l'API ne l'affecte pas).
            $authId = (int) ($this->authUser($request)['id'] ?? 0);
            ($this->events ?? Events::current())->dispatch(new EmployeeCreated($saved, null, $authId > 0 ? $authId : null, 'api'));
        }
        $this->auditLogger->log($request, 'user.created', 'user', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $this->withoutSecrets($data));
        return Response::json($this->withoutSecrets($saved), 201);
    }

    /** PUT /api/v1/users/{id} */
    public function update(Request $request): Response
    {
        $id  = (int) $request->param('id');
        $old = $this->requireUser($id);
        $this->permissions->requireUserAccess($this->authUser($request), 'employees.update', $id, $this->storeIdsOf($id));

        $body    = $request->json() ?? [];
        $data    = $this->normalizeUser(PayloadValidator::validate($body, ApiPayloadRules::user(), true));
        // Désactiver un Owner : même garde que la suppression (au moins un Owner actif, pas soi-même).
        if (!empty($old['is_active']) && array_key_exists('is_active', $data) && empty($data['is_active'])) {
            $this->ownerGuard->assertCanRemoveOwner((int) ($this->authUser($request)['id'] ?? 0), $id);
            $this->ownerGuard->assertNotSelfUnlessOwner((int) ($this->authUser($request)['id'] ?? 0), $id);
        }
        $newHash = $this->hashFromPayload($body);
        if ($data === [] && $newHash === null) {
            throw new ValidationException(['body' => __('val_no_updatable_field')], __('val_no_updatable_field'));
        }
        $this->assertIdentityAvailable($data, $id);
        if ($newHash !== null) {
            $data['password_hash'] = $newHash;
        }

        $saved = $this->users->save(array_merge($data, ['id' => $id]));

        // Nouveau mot de passe ou compte désactivé : cookies « rester connecté » et jetons d'API révoqués.
        if ($newHash !== null || (!empty($old['is_active']) && array_key_exists('is_active', $data) && empty($data['is_active']))) {
            $this->revoker?->revokeAllFor($id);
        }

        $this->auditLogger->logUpdate($request, 'user.updated', 'user', resourceId: $id, oldData: $this->withoutSecrets($old), newData: $this->withoutSecrets($saved), extraContext: $this->withoutSecrets($data));
        return Response::json($this->withoutSecrets($saved));
    }

    /** DELETE /api/v1/users/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $this->requireUser($id);
        $this->permissions->requireUserAccess($this->authUser($request), 'employees.delete', $id, $this->storeIdsOf($id));
        $this->ownerGuard->assertCanRemoveOwner((int) ($this->authUser($request)['id'] ?? 0), $id);
        $this->ownerGuard->assertNotSelfUnlessOwner((int) ($this->authUser($request)['id'] ?? 0), $id);

        // Anonymisation plutôt qu'effacement (même résultat sous SQLite et MySQL, historique conservé).
        $this->anonymizer()->anonymize($id);
        $this->auditLogger->log($request, 'user.deleted', 'user', resourceId: $id);
        return Response::empty();
    }

    private function authUser(Request $request): array
    {
        return $request->getAttribute('auth_user') ?? [];
    }

    private function requireUser(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }
        return $user;
    }

    /** @return int[] */
    private function storeIdsOf(int $userId): array
    {
        return array_map(fn(array $m): int => (int) $m['store_id'], $this->storeUsers->findByUser($userId));
    }

    /** Code employé en majuscules (comme le formulaire web) ; nom affiché « Nom Prénom » à défaut (UserDisplayName). */
    private function normalizeUser(array $data): array
    {
        if (isset($data['employee_code'])) {
            $data['employee_code'] = strtoupper($data['employee_code']);
        }
        if (!isset($data['display_name']) && isset($data['first_name'], $data['last_name'])) {
            $data['display_name'] = UserDisplayName::resolve(null, $data['first_name'], $data['last_name']);
        } elseif (isset($data['display_name'])) {
            $data['display_name'] = UserDisplayName::resolve($data['display_name'], $data['first_name'] ?? null, $data['last_name'] ?? null);
        }
        return $data;
    }

    /** E-mail et code employé uniques : un doublon finissait en erreur SQL (500). */
    private function assertIdentityAvailable(array $data, int $userId): void
    {
        if (isset($data['email'])) {
            $other = $this->users->findByEmail($data['email']);
            if ($other !== null && (int) $other['id'] !== $userId) {
                throw new ValidationException(['email' => __('email_taken')], __('email_taken'));
            }
        }
        if (isset($data['employee_code'])) {
            $other = $this->users->findByEmployeeCode($data['employee_code']);
            if ($other !== null && (int) $other['id'] !== $userId) {
                throw new ValidationException(['employee_code' => __('val_employee_code_taken')], __('val_employee_code_taken'));
            }
        }
    }

    /** Hash du champ `password` du corps, null s'il est absent ; 422 s'il est trop court. */
    private function hashFromPayload(array $data): ?string
    {
        if (!isset($data['password']) || $data['password'] === '') {
            return null;
        }
        $password = (string) $data['password'];
        if (!PasswordPolicy::isLongEnough($password)) {
            throw new ValidationException(['password' => __('password_too_short')]);
        }
        return PasswordHasher::hash($password);
    }

    private function withoutSecrets(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
