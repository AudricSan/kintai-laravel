<?php

namespace Kintai\Bundles\Notebook\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Notebook\NotebookAccess;
use Livewire\Component;

/**
 * Widget « Notes d'équipe » du tableau de bord gérant : les cinq premières notes visibles (épinglées d'abord).
 */
class ManagerWidget extends Component
{
    use ResolvesViews;

    public const LIMIT = 5;

    protected function title(): string
    {
        return __('Team notes');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        return app('view')->make($this->viewName('widget'), [
            'title' => $this->title(),
            'entries' => app(NotebookAccess::class)->visible($user)->limit(self::LIMIT)->get(),
        ]);
    }
}
