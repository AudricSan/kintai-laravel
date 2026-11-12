<?php

namespace App\Planning;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Magasin et période affichés par la frise (page et impression) : le magasin est limité aux magasins où
 * l'acteur a `shifts.view`, la période dépend du mode (jour, 3 jours, semaine selon le début de semaine du magasin).
 */
final readonly class TimelineViewport
{
    public const VIEWS = ['day', '3days', 'week'];

    /**
     * @param  Collection<int, Store>  $stores  Magasins visibles de l'acteur.
     */
    public function __construct(
        public Collection $stores,
        public ?Store $store,
        public CarbonImmutable $firstDay,
        public int $numDays,
        public string $view,
    ) {}

    public static function resolve(User $actor, ?string $storeId, ?string $start, ?string $view): self
    {
        $viewable = app(Authorizer::class)->storeIdsFor($actor, 'shifts.view');
        $stores = Store::query()
            ->when($viewable !== null, fn ($query) => $query->whereIn('id', $viewable))
            ->orderBy('name')
            ->get();

        $store = $stores->firstWhere('id', (int) $storeId) ?? $stores->first();
        $view = in_array($view, self::VIEWS, true) ? (string) $view : 'week';

        $anchor = self::parseDate($start);
        $numDays = match ($view) {
            'day' => 1,
            '3days' => 3,
            default => 7,
        };
        $firstDay = $view === 'week' ? WeekRange::firstDay($anchor, $store->week_start_day ?? 1) : $anchor;

        return new self($stores, $store, $firstDay, $numDays, $view);
    }

    public function lastDay(): CarbonImmutable
    {
        return $this->firstDay->addDays($this->numDays - 1);
    }

    public function previousStart(): string
    {
        return $this->firstDay->subDays($this->numDays)->toDateString();
    }

    public function nextStart(): string
    {
        return $this->firstDay->addDays($this->numDays)->toDateString();
    }

    private static function parseDate(?string $date): CarbonImmutable
    {
        if ($date !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return CarbonImmutable::parse($date)->startOfDay();
        }

        return CarbonImmutable::today();
    }
}
