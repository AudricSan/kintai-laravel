<?php

namespace Kintai\Bundles\StorePhotos;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kintai\Bundles\StorePhotos\Models\StorePhotoImage;
use Kintai\Bundles\StorePhotos\Models\StorePhotoSubmission;
use Kintai\Contracts\Activity\ActivityRecorder;
use Throwable;

/**
 * Écritures des photos : envoi (les envois d'un même jour pour un magasin s'ajoutent à un seul rapport), rotation,
 * suppression. Les droits sont revérifiés ici (`PhotoAccess`) ; le journal ne garde jamais le contenu des notes.
 *
 * Un refus de droit lève `AuthorizationException`, un fichier refusé une `ValidationException` (champ `photos`).
 */
class PhotoActions
{
    public const MAX_FILES = 20;

    public const MAX_TOTAL_BYTES = 40 * 1024 * 1024;

    public const NOTES_MAX = 2000;

    public function __construct(
        private readonly PhotoAccess $access,
        private readonly PhotoStorage $storage,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Envoie des photos dans un magasin : toutes sont contrôlées et ré-encodées avant la moindre écriture ; si l'une est
     * refusée, rien n'est enregistré.
     *
     * @param  list<array{name: string, contents: string}>  $files  Nom d'origine (affichage seulement) et contenu.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function submit(User $actor, int $storeId, ?string $notes, array $files): StorePhotoSubmission
    {
        if (! $this->access->canCreateIn($actor, $storeId)) {
            throw new AuthorizationException;
        }

        if ($files === [] || count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['photos' => __('Send between 1 and :max photos.', ['max' => self::MAX_FILES])]);
        }

        if (array_sum(array_map(fn (array $file): int => strlen($file['contents']), $files)) > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages(['photos' => __('The photos weigh more than 40 MB in total. Send fewer or lighter photos.')]);
        }

        $notes = $notes === null ? null : mb_substr(trim($notes), 0, self::NOTES_MAX);
        $written = [];

        try {
            $stored = [];

            foreach ($files as $file) {
                try {
                    $meta = $this->storage->store($file['contents']);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(['photos' => __('":name" is not a valid image (JPEG, PNG, GIF or WebP).', ['name' => mb_substr($file['name'], 0, 80)])]);
                }

                $written[] = $meta['path'];
                $stored[] = [$file['name'], $meta];
            }

            /** @var array{StorePhotoSubmission, bool} $result */
            $result = DB::transaction(function () use ($actor, $storeId, $notes, $stored): array {
                $submission = $this->today($storeId);
                $merged = $submission !== null;

                if ($submission === null) {
                    $submission = StorePhotoSubmission::create(['store_id' => $storeId, 'created_by' => $actor->id, 'notes' => $notes === '' ? null : $notes]);
                } elseif ($notes !== null && $notes !== '') {
                    $submission->notes = trim(($submission->notes ?? '')."\n".$notes);
                    $submission->save();
                }

                $last = StorePhotoImage::query()->where('submission_id', $submission->id)->max('sort_order');
                $order = $last === null ? 0 : (int) $last + 1;

                foreach ($stored as [$name, $meta]) {
                    StorePhotoImage::create([
                        'submission_id' => $submission->id, 'filename' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
                        'path' => $meta['path'], 'mime_type' => $meta['mime'], 'filesize' => $meta['size'],
                        'width' => $meta['width'], 'height' => $meta['height'], 'sort_order' => $order++,
                    ]);
                }

                return [$submission, $merged];
            });
        } catch (Throwable $e) {
            // Rien n'est enregistré : les fichiers déjà écrits partent aussi.
            foreach ($written as $name) {
                $this->storage->delete($name);
            }

            throw $e;
        }

        [$submission, $merged] = $result;
        $this->activity->record($merged ? 'photo.submission_merged' : 'photo.submission_created', $submission, $storeId, ['photos' => count($files)], $actor);

        return $submission;
    }

    /**
     * Tourne une photo de 90 degrés, vers la gauche ou la droite.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function rotate(User $actor, StorePhotoImage $image, bool $left): StorePhotoImage
    {
        $submission = $image->submission;

        if (! $this->access->canRotate($actor, $submission)) {
            throw new AuthorizationException;
        }

        try {
            $meta = $this->storage->rotate($image->path, $left ? 90 : -90);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['image' => __('The photo cannot be rotated.')]);
        }

        $image->forceFill(['filesize' => $meta['size'], 'width' => $meta['width'], 'height' => $meta['height']])->save();
        $this->activity->record('photo.image_rotated', $submission, $submission->store_id, ['image_id' => $image->id, 'degrees' => $left ? -90 : 90], $actor);

        return $image;
    }

    /**
     * Supprime un envoi avec ses photos et leurs fichiers.
     *
     * @throws AuthorizationException
     */
    public function delete(User $actor, StorePhotoSubmission $submission): void
    {
        if (! $this->access->canDelete($actor, $submission)) {
            throw new AuthorizationException;
        }

        $this->purge($submission);
        $this->activity->recordFor('photo.submission_deleted', 'store_photo_submission', $submission->id, $submission->store_id, [], $actor);
    }

    /**
     * Supprime un envoi, ses enregistrements et ses fichiers, sans contrôle de droit (rétention, suppression autorisée).
     */
    public function purge(StorePhotoSubmission $submission): void
    {
        DB::transaction(function () use ($submission): void {
            // Un par un, pour que chaque enregistrement supprime son fichier.
            foreach ($submission->images()->get() as $image) {
                $image->delete();
            }

            $submission->delete();
        });
    }

    /**
     * L'envoi du jour du magasin (jour du fuseau de l'application), s'il existe.
     */
    private function today(int $storeId): ?StorePhotoSubmission
    {
        $timezone = (string) config('kintai.timezone', 'UTC');
        $start = Carbon::now($timezone)->startOfDay()->utc();
        $end = Carbon::now($timezone)->endOfDay()->utc();

        return StorePhotoSubmission::query()->where('store_id', $storeId)->whereBetween('created_at', [$start, $end])->latest('created_at')->latest('id')->first();
    }
}
