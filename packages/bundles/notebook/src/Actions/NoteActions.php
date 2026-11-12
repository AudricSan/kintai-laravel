<?php

namespace Kintai\Bundles\Notebook\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kintai\Bundles\Notebook\Models\NotebookEntry;
use Kintai\Bundles\Notebook\NotebookAccess;
use Kintai\Bundles\Notebook\NotebookServiceProvider;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;

/**
 * Écritures du carnet, communes à la page et à l'API : droits (`NotebookAccess`), journal et notification. Les données
 * arrivent déjà validées. Un refus lève `AuthorizationException`.
 */
class NoteActions
{
    public function __construct(
        private readonly NotebookAccess $access,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function publish(User $actor, ?int $storeId, string $content, ?string $expiresOn): NotebookEntry
    {
        if (! $this->access->canCreateIn($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $entry = NotebookEntry::create([
            'author_id' => $actor->id,
            'store_id' => $storeId,
            'content' => trim($content),
            'pinned' => false,
            'expires_on' => $expiresOn,
        ]);

        $this->activity->record('notebook.created', $entry, $storeId, ['organization_wide' => $storeId === null], $actor);

        $recipients = $this->access->recipients($entry);
        if ($recipients !== []) {
            $this->notifier->notify(
                $recipients, NotebookServiceProvider::NOTIFICATION, 'New note from :author: ":excerpt"',
                ['author' => $actor->name, 'excerpt' => Str::limit(str_replace(["\r", "\n"], ' ', $entry->content), 80)],
                route('notebook.index', ['highlight' => $entry->id], false), $entry->id,
            );
        }

        return $entry;
    }

    /**
     * Seuls le texte et l'expiration changent : le magasin, l'auteur et l'épinglage restent ceux de la note.
     *
     * @throws AuthorizationException
     */
    public function update(User $actor, NotebookEntry $entry, string $content, ?string $expiresOn): NotebookEntry
    {
        if (! $this->access->canEdit($actor, $entry)) {
            throw new AuthorizationException;
        }

        $entry->content = trim($content);
        $entry->expires_on = $expiresOn === null ? null : Carbon::parse($expiresOn);
        $changed = array_keys($entry->getDirty());
        $entry->save();

        if ($changed !== []) {
            $this->activity->record('notebook.updated', $entry, $entry->store_id, ['fields' => $changed], $actor);
        }

        return $entry;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, NotebookEntry $entry): void
    {
        if (! $this->access->canEdit($actor, $entry)) {
            throw new AuthorizationException;
        }

        $entry->delete();
        $this->activity->record('notebook.deleted', $entry, $entry->store_id, ['organization_wide' => $entry->store_id === null], $actor);
    }

    /**
     * @throws AuthorizationException
     */
    public function togglePin(User $actor, NotebookEntry $entry): NotebookEntry
    {
        if (! $this->access->canManage($actor, $entry)) {
            throw new AuthorizationException;
        }

        $entry->pinned = ! $entry->pinned;
        $entry->save();
        $this->activity->record('notebook.pinned', $entry, $entry->store_id, ['pinned' => $entry->pinned], $actor);

        return $entry;
    }
}
