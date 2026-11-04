<?php

namespace App\Actions\Push;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre (ou rafraîchit) le jeton FCM d'un appareil mobile. Le jeton est unique : un appareil partagé qui
 * change de compte passe au nouveau compte.
 */
class RegisterDeviceToken
{
    public function handle(User $user, string $token, string $platform): DevicePushToken
    {
        $token = trim($token);
        $platform = strtolower(trim($platform));

        if ($token === '' || strlen($token) > 255) {
            throw ValidationException::withMessages(['token' => __('The device token is invalid.')]);
        }

        if (! in_array($platform, DevicePushToken::PLATFORMS, true)) {
            throw ValidationException::withMessages(['platform' => __('The platform must be ios or android.')]);
        }

        return DevicePushToken::query()->updateOrCreate(
            ['token' => $token],
            ['user_id' => $user->id, 'platform' => $platform, 'last_used_at' => now()],
        );
    }
}
