<?php

namespace Kintai\Bundles\Analytics\Livewire;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Analytics\AnalyticsAccess;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Base des pages du bundle : un magasin choisi parmi ceux dont l'utilisateur voit les statistiques (`payroll.view`) et une
 * période en jours. L'état d'un composant Livewire vient du navigateur : le droit sur le magasin est relu à chaque rendu.
 */
abstract class StorePage extends Component
{
    use ResolvesViews;

    /** Périodes proposées, en jours. */
    public const PERIODS = [7, 30, 90, 180, 365];

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: 30)]
    public int $period = 30;

    public function mount(): void
    {
        $stores = $this->stores();
        abort_if($stores === [], 403);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
        $this->period = $this->clampPeriod($this->period);
    }

    public function setPeriod(int $days): void
    {
        $this->period = $this->clampPeriod($days);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function access(): AnalyticsAccess
    {
        return app(AnalyticsAccess::class);
    }

    /**
     * @return array<int, string>
     */
    protected function stores(): array
    {
        return $this->access()->viewableStores($this->user());
    }

    /**
     * Le magasin choisi, après contrôle du droit (403 sinon).
     */
    protected function currentStore(): Store
    {
        $id = (int) $this->store;
        abort_unless($this->access()->canView($this->user(), $id), 403);

        return Store::query()->findOrFail($id);
    }

    protected function clampPeriod(int $days): int
    {
        return max(7, min(365, $days));
    }
}
