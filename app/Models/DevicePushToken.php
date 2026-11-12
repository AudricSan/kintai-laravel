<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Jeton FCM d'un appareil mobile.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $platform
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token', 'platform', 'last_used_at'])]
class DevicePushToken extends Model
{
    public const PLATFORMS = ['ios', 'android'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
