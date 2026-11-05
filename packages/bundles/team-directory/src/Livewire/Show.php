<?php

namespace Kintai\Bundles\TeamDirectory\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\TeamDirectory\Models\TeamProfile;
use Kintai\Bundles\TeamDirectory\TeamDirectoryServiceProvider;
use Kintai\Contracts\Stores\Colleague;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fiche d'un collègue : profil d'équipe et coordonnées que le Core permet de voir. Un inconnu, un compte inactif ou
 * anonymisé, un non-collègue et un profil sorti de l'annuaire sont indiscernables (404) ; on voit toujours sa propre fiche.
 */
#[Title('Team')]
class Show extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $userId = 0;

    public function mount(int $user): void
    {
        $this->userId = $user;
        $this->colleague();
    }

    private function colleague(): Colleague
    {
        $directory = app(ColleagueDirectory::class);
        $viewer = Auth::user();
        $colleague = $directory->colleague($viewer, $this->userId, array_keys($directory->stores($viewer, TeamDirectoryServiceProvider::FEATURE)));

        abort_if($colleague === null, 404);

        if (! $colleague->isSelf) {
            abort_if(TeamProfile::query()->where('user_id', $colleague->id)->where('show_in_directory', false)->exists(), 404);
        }

        return $colleague;
    }

    public function render(): View
    {
        $colleague = $this->colleague();

        return app('view')->make($this->viewName('show'), [
            'colleague' => $colleague,
            'profile' => TeamProfile::query()->where('user_id', $colleague->id)->first(),
        ]);
    }
}
