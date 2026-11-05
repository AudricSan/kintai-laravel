<?php

namespace Kintai\Bundles\StorePhotos\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\StorePhotos\PhotoAccess;
use Kintai\Bundles\StorePhotos\PhotoStorage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Photos de magasin » : les envois des magasins de l'utilisateur, groupés par jour, avec filtre par magasin et par jour.
 */
#[Title('Store photos')]
class Index extends Component
{
    use ResolvesViews;

    public const LIMIT = 100;

    #[Url(except: 0)]
    public int $storeId = 0;

    /** Jour au format `YYYY-MM-DD`, ou vide. */
    #[Url(except: '')]
    public string $date = '';

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

    public function resetFilters(): void
    {
        $this->reset('storeId', 'date');
    }

    public function render(): View
    {
        $user = $this->user();
        $stores = $this->access()->viewStores($user);
        $timezone = (string) config('kintai.timezone', 'UTC');

        $query = $this->access()->visible($user)->with(['store:id,name', 'images']);

        if ($this->storeId > 0 && isset($stores[$this->storeId])) {
            $query->where('store_id', $this->storeId);
        }

        // Jours proposés au filtre, comptés avant le filtre par jour lui-même.
        $submissions = $query->latest('created_at')->latest('id')->limit(self::LIMIT)->get();
        $days = [];

        foreach ($submissions as $submission) {
            $day = $submission->created_at?->copy()->timezone($timezone)->toDateString() ?? '';
            $days[$day] = ($days[$day] ?? 0) + 1;
        }

        krsort($days);

        $date = isset($days[$this->date]) ? $this->date : '';

        if ($date !== '') {
            $submissions = $submissions->filter(fn ($submission): bool => $submission->created_at?->copy()->timezone($timezone)->toDateString() === $date);
        }

        $storage = app(PhotoStorage::class);

        return app('view')->make($this->viewName('index'), [
            'stores' => $stores,
            'days' => $days,
            'selectedDate' => $date,
            'groups' => $submissions->groupBy(fn ($submission): string => $submission->created_at?->copy()->timezone($timezone)->toDateString() ?? ''),
            'versions' => $submissions->flatMap(fn ($submission) => $submission->images)->mapWithKeys(fn ($image): array => [$image->id => $storage->version($image->path)])->all(),
            'canCreate' => $this->access()->createStores($user) !== [],
            'isOwner' => $this->access()->isOwner($user),
            'today' => Carbon::now($timezone)->toDateString(),
        ]);
    }
}
