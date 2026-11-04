<?php

namespace App\Actions;

use App\Models\User;
use App\Support\AvatarProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Enregistre l'avatar d'un utilisateur sur le disque privé `local` et supprime l'ancien fichier.
 */
class StoreAvatar
{
    public function __construct(private readonly AvatarProcessor $processor) {}

    /**
     * @throws ValidationException si le contenu n'est pas une image décodable
     */
    public function handle(User $user, string $contents): void
    {
        try {
            $avatar = $this->processor->process($contents);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['avatar' => __('The file is not a readable image.')]);
        }

        $oldPath = $user->avatar_path;
        $path = 'avatars/user_'.$user->id.'_'.Str::lower(Str::random(16)).'.'.$avatar['extension'];

        Storage::disk('local')->put($path, $avatar['contents']);
        $user->forceFill(['avatar_path' => $path])->save();

        if ($oldPath !== null && $oldPath !== '' && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }
    }
}
