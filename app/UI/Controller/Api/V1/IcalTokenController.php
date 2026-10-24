<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;

final class IcalTokenController
{
    public function __construct(
        private readonly IcalTokenRepositoryInterface $icalTokens,
        private readonly UserTargetGuard $guard,
        private readonly StoreUserRepositoryInterface $storeUsers,
    ) {}

    /** GET /api/v1/users/{user_id}/ical-tokens */
    public function index(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.view');
        return Response::json($this->icalTokens->findByUser($userId));
    }

    /** GET /api/v1/users/{user_id}/ical-tokens/{store_id} */
    public function show(Request $request): Response
    {
        $userId  = (int) $request->param('user_id');
        $storeId = (int) $request->param('store_id');
        $this->guard->require($request, $userId, 'employees.view');

        $token = $this->icalTokens->findByUserAndStore($userId, $storeId);
        if ($token === null) {
            throw new NotFoundException(__('error_ical_token_not_found'));
        }

        return Response::json($token);
    }

    /** POST /api/v1/users/{user_id}/ical-tokens */
    public function store(Request $request): Response
    {
        $userId  = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $data    = $request->json() ?? [];
        $storeId = (int) ($data['store_id'] ?? 0);
        $this->assertMember($userId, $storeId);

        // findOrCreate plutôt qu'un save() inconditionnel : un second appel pour le
        // même user+store percuterait sinon la contrainte unique (user_id, store_id)
        // en base et remonterait en 500 au lieu de simplement rendre le token existant.
        $saved = $this->icalTokens->findOrCreateForUserAndStore($userId, $storeId);

        return Response::json($saved, 201);
    }

    /** DELETE /api/v1/users/{user_id}/ical-tokens/{store_id} */
    public function destroy(Request $request): Response
    {
        $userId  = (int) $request->param('user_id');
        $storeId = (int) $request->param('store_id');
        $this->guard->require($request, $userId, 'employees.update');

        if ($this->icalTokens->findByUserAndStore($userId, $storeId) === null) {
            throw new NotFoundException(__('error_ical_token_not_found'));
        }

        $this->icalTokens->deleteByUserAndStore($userId, $storeId);
        return Response::empty();
    }

    /** POST /api/v1/users/{user_id}/ical-tokens/{store_id}/regenerate */
    public function regenerate(Request $request): Response
    {
        $userId  = (int) $request->param('user_id');
        $storeId = (int) $request->param('store_id');
        $this->guard->require($request, $userId, 'employees.update');
        $this->assertMember($userId, $storeId);

        $existing = $this->icalTokens->findByUserAndStore($userId, $storeId);
        $saved    = $this->icalTokens->save(array_merge($existing ?? [], [
            'user_id'    => $userId,
            'store_id'   => $storeId,
            'token'      => bin2hex(random_bytes(32)),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        ]));

        return Response::json($saved);
    }

    /** Le flux iCal d'un magasin n'est délivré qu'à ses membres. */
    private function assertMember(int $userId, int $storeId): void
    {
        if ($this->storeUsers->findMembership($storeId, $userId) === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
    }
}
