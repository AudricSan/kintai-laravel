<?php

namespace Kintai\Bundles\StorePhotos\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Kintai\Bundles\StorePhotos\Models\StorePhotoImage;
use Kintai\Bundles\StorePhotos\PhotoAccess;
use Kintai\Bundles\StorePhotos\PhotoStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lecture d'une photo : les fichiers vivent sur le disque privé, cette route est la seule porte. Il faut être connecté
 * et soit voir les envois du magasin (`photos.view`), soit en être membre actif ; sinon 404, sans révéler que la photo
 * existe.
 */
class ImageController
{
    public function __invoke(Request $request, PhotoAccess $access, PhotoStorage $storage, int $image): Response
    {
        $user = $request->user();
        $model = StorePhotoImage::query()->with('submission')->find($image);

        abort_if(! $user instanceof User || $model === null || ! $access->canReadImage($user, $model), 404);

        $path = $storage->relativePath($model->path);
        abort_if($path === null, 404);

        return Storage::disk('local')->response($path, null, [
            // Type fixé par le serveur (ce que le traitement a écrit), jamais déduit du nom ou du contenu par le navigateur.
            'Content-Type' => $model->mime_type === 'image/png' ? 'image/png' : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
