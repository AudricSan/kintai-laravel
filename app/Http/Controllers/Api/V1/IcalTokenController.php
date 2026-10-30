<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\UserTargetGuard;
use App\Exceptions\NotFoundException;
use App\Models\IcalToken;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IcalTokenController
{
    public function __construct(
        private readonly UserTargetGuard $guard,
    ) {}

    /** GET /api/v1/users/{user_id}/ical-tokens */
    public function index(Request $request): Response
    {
        $userId = (int) $request->route('user_id');
        $this->guard->require($request, $userId, 'employees.view');
        return response()->json(IcalToken::findByUser($userId));
    }

    /** GET /api/v1/users/{user_id}/ical-tokens/{store_id} */
    public function show(Request $request): Response
    {
        $userId  = (int) $request->route('user_id');
        $storeId = (int) $request->route('store_id');
        $this->guard->require($request, $userId, 'employees.view');

        $token = IcalToken::findByUserAndStore($userId, $storeId);
        if ($token === null) {
            throw new NotFoundException(__('error_ical_token_not_found'));
        }

        return response()->json($token);
    }

    /** POST /api/v1/users/{user_id}/ical-tokens */
    public function store(Request $request): Response
    {
        $userId  = (int) $request->route('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $data    = $request->json()->all() ?? [];
        $storeId = (int) ($data['store_id'] ?? 0);
        $this->assertMember($userId, $storeId);

        // findOrCreate plutôt qu'un save() inconditionnel : un second appel pour le
        // même user+store percuterait sinon la contrainte unique (user_id, store_id)
        // en base et remonterait en 500 au lieu de simplement rendre le token existant.
        $saved = IcalToken::findOrCreateForUserAndStore($userId, $storeId);

        return response()->json($saved, 201);
    }

    /** DELETE /api/v1/users/{user_id}/ical-tokens/{store_id} */
    public function destroy(Request $request): Response
    {
        $userId  = (int) $request->route('user_id');
        $storeId = (int) $request->route('store_id');
        $this->guard->require($request, $userId, 'employees.update');

        if (IcalToken::findByUserAndStore($userId, $storeId) === null) {
            throw new NotFoundException(__('error_ical_token_not_found'));
        }

        IcalToken::deleteByUserAndStore($userId, $storeId);
        return response()->noContent();
    }

    /** POST /api/v1/users/{user_id}/ical-tokens/{store_id}/regenerate */
    public function regenerate(Request $request): Response
    {
        $userId  = (int) $request->route('user_id');
        $storeId = (int) $request->route('store_id');
        $this->guard->require($request, $userId, 'employees.update');
        $this->assertMember($userId, $storeId);

        $existing = IcalToken::findByUserAndStore($userId, $storeId);
        $saved    = IcalToken::put(array_merge($existing ?? [], [
            'user_id'    => $userId,
            'store_id'   => $storeId,
            'token'      => bin2hex(random_bytes(32)),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        ]));

        return response()->json($saved);
    }

    /** Le flux iCal d'un magasin n'est délivré qu'à ses membres. */
    private function assertMember(int $userId, int $storeId): void
    {
        if (StoreUser::findMembership($storeId, $userId) === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
    }
}
