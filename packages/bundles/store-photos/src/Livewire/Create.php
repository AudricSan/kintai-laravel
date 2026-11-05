<?php

namespace Kintai\Bundles\StorePhotos\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\StorePhotos\PhotoAccess;
use Kintai\Bundles\StorePhotos\PhotoActions;
use Kintai\Bundles\StorePhotos\PhotoStorage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Nouvel envoi de photos » : un magasin, des photos et des notes. Les photos sont contrôlées par leur contenu et
 * ré-encodées avant d'être enregistrées (voir `PhotoStorage`) ; les envois d'un même jour s'ajoutent au même rapport.
 */
#[Title('New photo upload')]
class Create extends Component
{
    use ResolvesViews;
    use WithFileUploads;

    public string $storeId = '';

    /** @var list<TemporaryUploadedFile> */
    public array $photos = [];

    public string $notes = '';

    public function mount(): void
    {
        $stores = $this->access()->createStores($this->user());
        abort_if($stores === [], 404);

        $this->storeId = (string) array_key_first($stores);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): PhotoAccess
    {
        return app(PhotoAccess::class);
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function submit(PhotoActions $actions): void
    {
        $this->validate([
            'storeId' => ['required', 'integer'],
            'photos' => ['required', 'array', 'min:1', 'max:'.PhotoActions::MAX_FILES],
            // Contrôle rapide ; le vrai contrôle (contenu décodé par GD) est dans `PhotoStorage`.
            'photos.*' => ['file', 'image', 'max:'.(PhotoStorage::MAX_BYTES / 1024)],
            'notes' => ['nullable', 'string', 'max:'.PhotoActions::NOTES_MAX],
        ]);

        $files = [];

        foreach ($this->photos as $photo) {
            $files[] = ['name' => $photo->getClientOriginalName(), 'contents' => (string) $photo->get()];
        }

        try {
            $submission = $actions->submit($this->user(), (int) $this->storeId, $this->notes, $files);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->addError('photos', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }

        foreach ($this->photos as $photo) {
            $photo->delete();
        }

        Flux::toast(variant: 'success', text: __('Photos sent.'));
        $this->redirectRoute('store-photos.show', ['id' => $submission->id], navigate: true);
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('create'), ['stores' => $this->access()->createStores($this->user())]);
    }
}
