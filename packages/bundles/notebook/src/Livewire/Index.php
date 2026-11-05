<?php

namespace Kintai\Bundles\Notebook\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Kintai\Bundles\Notebook\Models\NotebookEntry;
use Kintai\Bundles\Notebook\NotebookAccess;
use Kintai\Bundles\Notebook\NotebookServiceProvider;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Page du carnet : liste des notes visibles, publication, modification, suppression (après confirmation) et
 * épinglage. Les droits sont revérifiés à chaque action (voir `NotebookAccess`).
 */
#[Title('Notebook')]
class Index extends Component
{
    use ResolvesViews;

    #[Url(except: false)]
    public bool $showExpired = false;

    /** Note désignée par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    public string $content = '';

    /** Identifiant d'un magasin, ou vide (toute l'organisation). */
    public string $storeId = '';

    public string $expiresOn = '';

    #[Locked]
    public int $editingId = 0;

    public string $editContent = '';

    public string $editExpiresOn = '';

    #[Locked]
    public int $deletingId = 0;

    public function mount(): void
    {
        abort_if($this->access()->viewStores($this->user()) === [], 404);

        $stores = $this->access()->createStores($this->user());
        $this->storeId = $this->access()->canCreateOrganizationWide($this->user()) ? '' : (string) (array_key_first($stores) ?? '');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): NotebookAccess
    {
        return app(NotebookAccess::class);
    }

    public function publish(ActivityRecorder $activity, Notifier $notifier): void
    {
        $user = $this->user();
        $storeId = ctype_digit($this->storeId) ? (int) $this->storeId : null;
        abort_unless($this->access()->canCreateIn($user, $storeId), 403);

        $validated = $this->validate([
            'content' => ['required', 'string', 'max:5000'],
            'expiresOn' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entry = NotebookEntry::create([
            'author_id' => $user->id,
            'store_id' => $storeId,
            'content' => trim($validated['content']),
            'pinned' => false,
            'expires_on' => $validated['expiresOn'] ?: null,
        ]);

        $activity->record('notebook.created', $entry, $storeId, ['organization_wide' => $storeId === null], $user);

        $recipients = $this->access()->recipients($entry);
        if ($recipients !== []) {
            $notifier->notify(
                $recipients, NotebookServiceProvider::NOTIFICATION, 'New note from :author: ":excerpt"',
                ['author' => $user->name, 'excerpt' => Str::limit(str_replace(["\r", "\n"], ' ', $entry->content), 80)],
                route('notebook.index', ['highlight' => $entry->id], false), $entry->id,
            );
        }

        $this->reset('content', 'expiresOn');
        Flux::toast(variant: 'success', text: __('Note published.'));
    }

    public function startEditing(int $id): void
    {
        $entry = $this->findEditable($id);

        $this->editingId = $entry->id;
        $this->editContent = $entry->content;
        $this->editExpiresOn = $entry->expires_on?->toDateString() ?? '';
    }

    public function cancelEditing(): void
    {
        $this->reset('editingId', 'editContent', 'editExpiresOn');
    }

    public function saveEditing(ActivityRecorder $activity): void
    {
        $entry = $this->findEditable($this->editingId);

        $validated = $this->validate([
            'editContent' => ['required', 'string', 'max:5000'],
            'editExpiresOn' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entry->content = trim($validated['editContent']);
        $entry->expires_on = $validated['editExpiresOn'] ?: null;
        $changed = array_keys($entry->getDirty());
        $entry->save();

        if ($changed !== []) {
            $activity->record('notebook.updated', $entry, $entry->store_id, ['fields' => $changed], $this->user());
        }

        $this->cancelEditing();
        Flux::toast(variant: 'success', text: __('Note updated.'));
    }

    public function confirmDeletion(int $id): void
    {
        $this->deletingId = $this->findEditable($id)->id;

        Flux::modal('confirm-note-deletion')->show();
    }

    public function delete(ActivityRecorder $activity): void
    {
        $entry = $this->findEditable($this->deletingId);

        $entry->delete();
        $activity->record('notebook.deleted', $entry, $entry->store_id, ['organization_wide' => $entry->store_id === null], $this->user());

        $this->reset('deletingId');
        Flux::modal('confirm-note-deletion')->close();
        Flux::toast(variant: 'success', text: __('Note deleted.'));
    }

    public function togglePin(int $id, ActivityRecorder $activity): void
    {
        $entry = $this->findVisible($id);
        abort_unless($this->access()->canManage($this->user(), $entry), 403);

        $entry->pinned = ! $entry->pinned;
        $entry->save();

        $activity->record('notebook.pinned', $entry, $entry->store_id, ['pinned' => $entry->pinned], $this->user());
    }

    private function findVisible(int $id): NotebookEntry
    {
        $entry = NotebookEntry::query()->find($id);
        abort_if($entry === null || ! $this->access()->canView($this->user(), $entry), 404);

        return $entry;
    }

    private function findEditable(int $id): NotebookEntry
    {
        $entry = $this->findVisible($id);
        abort_unless($this->access()->canEdit($this->user(), $entry), 403);

        return $entry;
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        abort_if($access->viewStores($user) === [], 404);

        $entries = $access->visible($user, $this->showExpired)->get();

        // Les notes affichées sont vues : leurs notifications de publication sont lues.
        app(Notifier::class)->markRead($user, 'notebook_entry', $entries->modelKeys());

        return app('view')->make($this->viewName('index'), [
            'entries' => $entries,
            'createStores' => $access->createStores($user),
            'canOrganizationWide' => $access->canCreateOrganizationWide($user),
            'canCreate' => $access->canCreateOrganizationWide($user) || $access->createStores($user) !== [],
            'access' => $access,
            'user' => $user,
        ]);
    }
}
