<?php

namespace Kintai\Bundles\StorePhotos;

use App\Authorization\Authorizer;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Kintai\Bundles\StorePhotos\Models\StorePhotoImage;
use Kintai\Bundles\StorePhotos\Models\StorePhotoSubmission;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Settings\Settings;

/**
 * Rétention des photos (tâche planifiée chaque nuit) :
 *
 * 1. les envois plus vieux que `photos.retention_days` sont marqués archivés et les Owners sont prévenus une fois par envoi
 *    (notification `photo_archived`) ;
 * 2. après `photos.cleanup_delay` jours de plus, l'envoi et ses fichiers sont supprimés définitivement ;
 * 3. les fichiers du dossier des photos que plus aucun enregistrement ne désigne (magasin supprimé, base restaurée,
 *    réinitialisation des données) sont supprimés, passé un jour de grâce pour ne pas toucher un envoi en cours.
 */
class PhotoRetention
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Authorizer $authorizer,
        private readonly Notifier $notifier,
        private readonly PhotoActions $actions,
        private readonly PhotoStorage $storage,
    ) {}

    /**
     * @return array{retention_days: int, cleanup_delay: int, archived: int, notified: int, purged: int, orphans: int}
     */
    public function run(): array
    {
        $retention = max(1, $this->settings->int('photos.retention_days'));
        $delay = max(1, $this->settings->int('photos.cleanup_delay'));
        $result = ['retention_days' => $retention, 'cleanup_delay' => $delay, 'archived' => 0, 'notified' => 0, 'purged' => 0, 'orphans' => 0];
        $owners = $this->authorizer->activeOwnerIds();

        $due = StorePhotoSubmission::query()->whereNull('archived_at')->where('created_at', '<', Carbon::now()->subDays($retention))->orderBy('id')->get();

        foreach ($due as $submission) {
            $submission->forceFill(['archived_at' => Carbon::now()])->save();
            $result['archived']++;

            if ($owners !== []) {
                $this->notifier->notify($owners, StorePhotosServiceProvider::NOTIFICATION, 'The photos of :date (:store) are past their retention period; they will be permanently deleted in :days days.', [
                    'store' => Store::withTrashed()->find($submission->store_id)->name ?? '#'.$submission->store_id,
                    'date' => $submission->created_at?->format('Y-m-d') ?? '?',
                    'days' => (string) $delay,
                ], route('store-photos.show', ['id' => $submission->id], false), $submission->id);
                $result['notified']++;
            }
        }

        foreach (StorePhotoSubmission::query()->where('created_at', '<', Carbon::now()->subDays($retention + $delay))->orderBy('id')->get() as $submission) {
            $this->actions->purge($submission);
            $result['purged']++;
        }

        $result['orphans'] = $this->removeOrphans();

        return $result;
    }

    private function removeOrphans(): int
    {
        $known = array_flip(StorePhotoImage::query()->pluck('path')->all());
        $limit = Carbon::now()->subDay()->getTimestamp();
        $removed = 0;

        foreach ($this->storage->names() as $name) {
            if (! isset($known[$name]) && $this->storage->lastModified($name) < $limit) {
                $this->storage->delete($name);
                $removed++;
            }
        }

        return $removed;
    }
}
