<?php

namespace Tests\Fixtures\Bundles\DemoBundle;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kintai\Contracts\Api\ApiController;
use Kintai\Contracts\Api\ApiException;

/**
 * Contrôleur d'API du bundle de démonstration : montre ce que le contrat fournit à un bundle.
 */
class DemoApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['user_id' => $this->actor($request)->getAuthIdentifier(), 'per_page' => $this->perPage($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => ['required', 'string']]);

        return response()->json(['ok' => true], 201);
    }

    public function conflict(): JsonResponse
    {
        throw new ApiException(409, 'demo_conflict', 'Demo conflict.');
    }
}
