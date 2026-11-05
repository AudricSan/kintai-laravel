<?php

namespace Kintai\Bundles\Notebook\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Notebook\Actions\NoteActions;
use Kintai\Bundles\Notebook\Models\NotebookEntry;
use Kintai\Bundles\Notebook\NotebookAccess;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Page du carnet : liste des notes visibles, publication, modification, suppression (après confirmation) et
 * épinglage. Les droits sont revérifiés à chaque action (voir `NotebookAccess` et `NoteActions`).
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

    public function publish(NoteActions $actions): void
    {
        $storeId = ctype_digit($this->storeId) ? (int) $this->storeId : null;
        abort_unless($this->access()->canCreateIn($this->user(), $storeId), 403);

        $validated = $this->validate([
            'content' => ['required', 'string', 'max:5000'],
            'expiresOn' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $actions->publish($this->user(), $storeId, $validated['content'], $validated['expiresOn'] ?: null);

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

    public function saveEditing(NoteActions $actions): void
    {
        $entry = $this->findEditable($this->editingId);

        $validated = $this->validate([
            'editContent' => ['required', 'string', 'max:5000'],
            'editExpiresOn' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $actions->update($this->user(), $entry, $validated['editContent'], $validated['editExpiresOn'] ?: null);

        $this->cancelEditing();
        Flux::toast(variant: 'success', text: __('Note updated.'));
    }

    public function confirmDeletion(int $id): void
    {
        $this->deletingId = $this->findEditable($id)->id;

        Flux::modal('confirm-note-deletion')->show();
    }

    public function delete(NoteActions $actions): void
    {
        $actions->delete($this->user(), $this->findEditable($this->deletingId));

        $this->reset('deletingId');
        Flux::modal('confirm-note-deletion')->close();
        Flux::toast(variant: 'success', text: __('Note deleted.'));
    }

    public function togglePin(int $id, NoteActions $actions): void
    {
        $actions->togglePin($this->user(), $this->findVisible($id));
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
