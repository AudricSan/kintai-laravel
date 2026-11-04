<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class RemoveAvatar
{
    public function handle(User $user): void
    {
        $path = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        if ($path !== null && $path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
