<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AvatarController extends Controller
{
    /**
     * Tout utilisateur connecté peut voir l'avatar d'un autre ; le fichier reste sur le disque privé.
     */
    public function show(User $user): Response
    {
        $path = $user->avatar_path;
        $disk = Storage::disk('local');

        abort_if($path === null || ! str_starts_with($path, 'avatars/') || ! $disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
