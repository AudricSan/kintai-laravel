<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;

final class PushTokenController
{
    // « web » n'existe plus : les navigateurs s'abonnent en web push natif (POST /notifications/push-subscribe).
    private const PLATFORMS = ['android', 'ios'];

    public function __construct(
        private readonly DevicePushTokenRepositoryInterface $tokens,
        private readonly UserTargetGuard $guard,
    ) {}

    /**
     * POST /api/v1/users/{user_id}/push-tokens
     * Enregistre (ou réassigne) un jeton FCM pour cet utilisateur. Un même jeton peut
     * migrer d'un utilisateur à l'autre sur un appareil partagé (ex. tablette de
     * magasin passée de main en main entre employés) : upsert par jeton, pas par user.
     */
    public function store(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $data     = $request->json() ?? [];
        $token    = trim((string) ($data['token'] ?? ''));
        $platform = (string) ($data['platform'] ?? '');

        if ($token === '') {
            throw new ValidationException(['token' => [__('validation_device_token_required')]]);
        }
        if ($platform !== '' && !in_array($platform, self::PLATFORMS, true)) {
            throw new ValidationException(['platform' => [__('validation_platform_invalid')]]);
        }

        $record = $this->tokens->save([
            'user_id'  => $userId,
            'token'    => $token,
            'platform' => $platform ?: null,
        ]);

        return Response::json($record, 201);
    }

    /**
     * DELETE /api/v1/users/{user_id}/push-tokens
     * Body : { "token": "..." } — désenregistre un appareil (déconnexion, désinstallation).
     */
    public function destroy(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $token = trim((string) ($request->json('token') ?? ''));
        if ($token === '') {
            throw new ValidationException(['token' => [__('validation_device_token_required')]]);
        }

        $this->tokens->deleteByToken($token, $userId);
        return Response::empty();
    }
}
