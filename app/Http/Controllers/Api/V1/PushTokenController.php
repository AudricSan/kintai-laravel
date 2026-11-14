<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Push\RegisterDeviceToken;
use App\Actions\Push\RemoveDeviceToken;
use App\Http\Requests\Api\V1\StorePushTokenRequest;
use App\Http\Resources\V1\DevicePushTokenResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Jetons FCM des appareils mobiles d'un utilisateur (notifications push de l'application).
 */
class PushTokenController extends ApiController
{
    public function store(Request $request, int $user, RegisterDeviceToken $register): DevicePushTokenResource
    {
        $target = $this->targetUser($request, $user, 'employees.update');

        $data = $this->validated(StorePushTokenRequest::class);

        $record = $register->handle($target, $data['token'], $data['platform']);

        // 201 à la création, 200 si le jeton de cet appareil était déjà enregistré (rafraîchi).
        return new DevicePushTokenResource($record);
    }

    public function destroy(Request $request, int $user, RemoveDeviceToken $remove): Response
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        $remove->handle($target, $data['token']);

        return response()->noContent();
    }
}
