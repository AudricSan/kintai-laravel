<?php

namespace Kintai\Bundles\Analytics\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Analytics\AnalyticsAccess;
use Kintai\Bundles\Analytics\DashboardFigures;
use Livewire\Component;

/**
 * Base des widgets du tableau de bord gérant : chiffres des magasins dont l'utilisateur voit les statistiques
 * (`payroll.view`, gérant restreint à ses magasins). Le widget relit lui-même l'utilisateur connecté.
 */
abstract class Widget extends Component
{
    use ResolvesViews;

    /**
     * @return array<int, string>
     */
    protected function stores(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return app(AnalyticsAccess::class)->viewableStores($user);
    }

    protected function figures(): DashboardFigures
    {
        return app(DashboardFigures::class);
    }
}
