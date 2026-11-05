<?php

namespace Kintai\Bundles\StorePhotos\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\StorePhotos\PhotoAccess;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Settings\Settings as InstanceSettings;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Réglages de rétention des photos, réservés à l'Owner : durée de conservation, puis délai avant suppression définitive.
 */
#[Title('Photo settings')]
class Settings extends Component
{
    use ResolvesViews;

    public int $retentionDays = 14;

    public int $cleanupDelay = 7;

    public function mount(InstanceSettings $settings): void
    {
        abort_unless(app(PhotoAccess::class)->isOwner($this->user()), 403);

        $this->retentionDays = $settings->int('photos.retention_days');
        $this->cleanupDelay = $settings->int('photos.cleanup_delay');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function save(InstanceSettings $settings, ActivityRecorder $activity): void
    {
        abort_unless(app(PhotoAccess::class)->isOwner($this->user()), 403);

        $this->validate([
            'retentionDays' => ['required', 'integer', 'min:1', 'max:365'],
            'cleanupDelay' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $before = ['retention_days' => $settings->int('photos.retention_days'), 'cleanup_delay' => $settings->int('photos.cleanup_delay')];
        $settings->set('photos.retention_days', $this->retentionDays);
        $settings->set('photos.cleanup_delay', $this->cleanupDelay);

        $activity->record('photo.settings_updated', null, null, ['before' => $before, 'after' => ['retention_days' => $this->retentionDays, 'cleanup_delay' => $this->cleanupDelay]], $this->user());
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('settings'));
    }
}
