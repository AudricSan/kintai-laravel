<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;

final class AuthController
{
    /**
     * Durée de vie d'un jeton quand le client ne fournit pas expires_in, et plafond de expires_in : 90 jours.
     * Avant l'audit du 13/10/2026, un jeton sans expires_in n'expirait jamais.
     */
    public const TOKEN_TTL = 90 * 86400;

    public function __construct(
        private readonly AuthService $auth,
        private readonly ApiTokenRepositoryInterface $tokens,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * POST /api/v1/auth/login
     * Body : { "email": "...", "password": "..." }
     *     OU { "employee_code": "...", "store_code": "...", "password": "..." }
     */
    public function login(Request $request): Response
    {
        $data     = $request->json() ?? [];
        $password = (string) ($data['password'] ?? '');
        $name     = (string) ($data['token_name'] ?? 'API');

        // Contrôlé avant l'authentification : une requête mal formée n'est pas un échec de connexion.
        $ttl = self::TOKEN_TTL;
        if (array_key_exists('expires_in', $data)) {
            $requested = is_bool($data['expires_in']) ? false : filter_var($data['expires_in'], FILTER_VALIDATE_INT);
            if ($requested === false || $requested <= 0) {
                return Response::apiError(
                    'expires_in doit être un nombre entier de secondes strictement positif.',
                    422, 'INVALID_EXPIRES_IN'
                );
            }
            $ttl = min($requested, self::TOKEN_TTL);
        }

        if (isset($data['email'])) {
            $ok = $this->auth->attempt((string) $data['email'], $password);
        } elseif (isset($data['employee_code'], $data['store_code'])) {
            $ok = $this->auth->attemptByCode(
                (string) $data['employee_code'],
                (string) $data['store_code'],
                $password,
            );
        } else {
            return Response::apiError(
                'Identifiants manquants. Fournir email+password ou employee_code+store_code+password.',
                422, 'MISSING_CREDENTIALS'
            );
        }

        if (!$ok) {
            // Lu par LoginThrottleMiddleware : seuls les échecs comptent dans la limitation.
            $request->setAttribute('auth_failed', true);
            $this->auditLogger->log($request, 'auth.api_login_failed', 'user', null, [
                'mode' => isset($data['email']) ? 'email' : 'code',
            ]);
            return Response::apiError('Identifiants invalides.', 401, 'INVALID_CREDENTIALS');
        }

        // Mot de passe par défaut « 0000 » ou trop court : pas de jeton. Sur le web, l'utilisateur est seulement
        // invité à le changer (PasswordReminderMiddleware) ; un jeton API, lui, est un accès durable et sans
        // écran pour le rappeler : quiconque connaît un code employé et un code magasin en obtiendrait un.
        // Les identifiants sont bons : ce n'est pas un échec pour la limitation des tentatives.
        if (!PasswordPolicy::isLongEnough($password)) {
            $userId = (int) ($this->auth->user()['id'] ?? 0);
            $this->auth->logout();
            $this->auditLogger->log($request, 'auth.api_login_password_change_required', 'user', $userId ?: null, [
                'mode' => isset($data['email']) ? 'email' : 'code',
            ], null, $userId ?: null);
            return Response::apiError(
                'Mot de passe à changer : connectez-vous sur le site pour choisir un nouveau mot de passe (8 caractères minimum).',
                403, 'PASSWORD_CHANGE_REQUIRED'
            );
        }

        $user      = $this->auth->user();
        $rawToken  = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $record = $this->tokens->save([
            'user_id'    => (int) $user['id'],
            'token'      => hash_token($rawToken),
            'name'       => $name,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Détruire la session PHP créée par AuthService::attempt (l'API est stateless)
        $this->auth->logout();

        $this->auditLogger->log($request, 'api_token.created', 'api_token', (int) $record['id'], [
            'name' => $name,
        ], null, (int) $user['id']);

        return Response::json([
            'token'      => $rawToken,
            'token_id'   => $record['id'],
            'expires_at' => $expiresAt,
            'user'       => $this->sanitizeUser($user),
        ], 201);
    }

    /** POST /api/v1/auth/logout — révoque le token courant */
    public function logout(Request $request): Response
    {
        $tokenRecord = $request->getAttribute('api_token');
        if ($tokenRecord !== null) {
            $this->tokens->delete((int) $tokenRecord['id']);
            $this->auditLogger->log($request, 'api_token.revoked', 'api_token', (int) $tokenRecord['id'], [], null, (int) ($tokenRecord['user_id'] ?? 0) ?: null);
        }

        return Response::empty();
    }

    /** GET /api/v1/auth/me — utilisateur courant */
    public function me(Request $request): Response
    {
        $user = $request->getAttribute('auth_user');
        return Response::json($this->sanitizeUser($user));
    }

    /** GET /api/v1/auth/tokens — liste les tokens du user courant */
    public function listTokens(Request $request): Response
    {
        $user   = $request->getAttribute('auth_user');
        $tokens = $this->tokens->findByUserId((int) $user['id']);

        return Response::json(array_map(fn($t) => $this->sanitizeToken($t), $tokens));
    }

    /** DELETE /api/v1/auth/tokens/{id} — révoque un token spécifique */
    public function revokeToken(Request $request): Response
    {
        $id          = (int) $request->param('id');
        $user        = $request->getAttribute('auth_user');
        $tokenRecord = $this->tokens->findById($id);

        if ($tokenRecord === null || (int) $tokenRecord['user_id'] !== (int) $user['id']) {
            throw new NotFoundException(__('error_token_not_found'));
        }

        $this->tokens->delete($id);
        $this->auditLogger->log($request, 'api_token.revoked', 'api_token', $id, [], null, (int) $user['id']);
        return Response::empty();
    }

    /** GET /api/v1/ping */
    public function ping(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'version' => 'v1']);
    }

    private function sanitizeUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }

    private function sanitizeToken(array $token): array
    {
        unset($token['token']);
        return $token;
    }
}
