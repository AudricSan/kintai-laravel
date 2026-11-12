<?php

namespace Kintai\Bundles\StorePhotos\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\StorePhotos\Models\StorePhotoImage;
use Kintai\Bundles\StorePhotos\Models\StorePhotoSubmission;
use Kintai\Bundles\StorePhotos\PhotoAccess;
use Kintai\Bundles\StorePhotos\PhotoActions;
use Kintai\Bundles\StorePhotos\PhotoStorage;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Un envoi de photos : ses photos (visionneuse en fenêtre), rotation et suppression selon les droits.
 */
#[Title('Photo report')]
class Show extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $submissionId = 0;

    /** Position de la photo ouverte dans la visionneuse, ou -1. */
    public int $viewing = -1;

    public function mount(int $id): void
    {
        $this->submissionId = $this->submission($id)->id;
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

    /** L'envoi, seulement si l'utilisateur voit les envois de son magasin (404 sinon). */
    private function submission(int $id): StorePhotoSubmission
    {
        $submission = StorePhotoSubmission::query()->find($id);
        abort_if($submission === null || ! $this->access()->canView($this->user(), $submission), 404);

        return $submission;
    }

    public function open(int $index): void
    {
        $this->viewing = $index;
        Flux::modal('photo-viewer')->show();
    }

    public function step(int $by): void
    {
        $count = $this->submission($this->submissionId)->images()->count();
        $this->viewing = $count === 0 ? -1 : (($this->viewing + $by) % $count + $count) % $count;
    }

    public function rotate(int $imageId, bool $left, PhotoActions $actions): void
    {
        $submission = $this->submission($this->submissionId);
        $image = StorePhotoImage::query()->where('submission_id', $submission->id)->find($imageId);
        abort_if($image === null, 404);

        try {
            $actions->rotate($this->user(), $image, $left);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException) {
            Flux::toast(variant: 'danger', text: __('The photo cannot be rotated.'));
        }
    }

    public function confirmDeletion(): void
    {
        abort_unless($this->access()->canDelete($this->user(), $this->submission($this->submissionId)), 403);
        Flux::modal('confirm-photo-deletion')->show();
    }

    public function delete(PhotoActions $actions): void
    {
        try {
            $actions->delete($this->user(), $this->submission($this->submissionId));
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::modal('confirm-photo-deletion')->close();
        Flux::toast(variant: 'success', text: __('Photo report deleted.'));
        $this->redirectRoute('store-photos.index', navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $submission = $this->submission($this->submissionId)->load(['store:id,name', 'author:id,name']);
        $images = $submission->images()->get();

        // L'envoi est ouvert : la notification de rétention qui le désigne est lue.
        app(Notifier::class)->markRead($user, 'photo_submission', $submission->id);

        $storage = app(PhotoStorage::class);

        return app('view')->make($this->viewName('show'), [
            'submission' => $submission,
            'images' => $images,
            'versions' => $images->mapWithKeys(fn (StorePhotoImage $image): array => [$image->id => $storage->version($image->path)])->all(),
            'viewed' => $this->viewing >= 0 ? $images->values()->get($this->viewing) : null,
            'canRotate' => $this->access()->canRotate($user, $submission),
            'canDelete' => $this->access()->canDelete($user, $submission),
        ]);
    }
}
