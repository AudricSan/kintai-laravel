<?php

namespace Kintai\Bundles\TeamDirectory\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\TeamDirectory\Models\TeamProfile;
use Kintai\Bundles\TeamDirectory\TeamDirectoryServiceProvider;
use Kintai\Contracts\Stores\Colleague;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Liste des collègues des magasins où la fonctionnalité est activée. Les profils qui ont quitté l'annuaire n'y figurent pas.
 */
#[Title('Team')]
class Index extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $search = '';

    /** Identifiant d'un magasin, ou vide (tous). */
    #[Url(except: '')]
    public string $store = '';

    public function mount(): void
    {
        abort_if($this->stores() === [], 404);
    }

    /**
     * @return array<int, string>
     */
    private function stores(): array
    {
        return app(ColleagueDirectory::class)->stores(Auth::user(), TeamDirectoryServiceProvider::FEATURE);
    }

    public function render(): View
    {
        $stores = $this->stores();
        abort_if($stores === [], 404);

        $storeIds = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? [(int) $this->store] : array_keys($stores);
        $hidden = TeamProfile::query()->where('show_in_directory', false)->pluck('user_id')->all();
        $term = mb_strtolower(trim($this->search));

        $colleagues = array_values(array_filter(
            app(ColleagueDirectory::class)->colleagues(Auth::user(), $storeIds),
            fn (Colleague $colleague): bool => ! in_array($colleague->id, $hidden, true)
                && ($term === '' || str_contains(mb_strtolower($colleague->name), $term)),
        ));

        return app('view')->make($this->viewName('index'), ['colleagues' => $colleagues, 'stores' => $stores]);
    }
}
