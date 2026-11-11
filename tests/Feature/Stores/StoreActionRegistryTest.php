<?php

namespace Tests\Feature\Stores;

use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Kintai\Contracts\Stores\StoreAction;
use Kintai\Contracts\Stores\StoreActionRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Contrat `StoreActionRegistry` : visibilité par personne et par magasin, ordre, doublons, affichage sur la liste des magasins.
 */
class StoreActionRegistryTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Bravo']);
    }

    private function registry(): StoreActionRegistry
    {
        return app(StoreActionRegistry::class);
    }

    private function action(string $key, int $order = 100, ?callable $visible = null): StoreAction
    {
        return new StoreAction($key, 'Action '.$key, 'stores.create', $order, $visible ?? fn (): bool => true, 'chart-bar');
    }

    public function test_without_any_registered_action_nothing_is_returned(): void
    {
        $this->assertSame([], $this->registry()->actionsFor($this->owner(), $this->a->id));
    }

    public function test_actions_are_filtered_by_viewer_and_store_and_sorted_by_order(): void
    {
        $viewer = $this->owner();
        $this->registry()->register($this->action('late', 20));
        $this->registry()->register($this->action('early', 10));
        $this->registry()->register($this->action('hidden', 5, fn (Authenticatable $viewer, int $id): bool => false));
        $this->registry()->register($this->action('only-bravo', 1, fn (Authenticatable $viewer, int $id): bool => $id === $this->b->id));

        $keys = fn (int $storeId): array => array_map(fn (StoreAction $a): string => $a->key, $this->registry()->actionsFor($viewer, $storeId));

        $this->assertTrue($this->registry()->has('late'));
        $this->assertFalse($this->registry()->has('missing'));
        $this->assertSame(['early', 'late'], $keys($this->a->id));
        $this->assertSame(['only-bravo', 'early', 'late'], $keys($this->b->id));
        $this->assertSame(route('stores.create', ['store' => 7]), $this->registry()->actionsFor($viewer, $this->a->id)[0]->url(7));
    }

    public function test_a_duplicate_key_is_refused(): void
    {
        $this->registry()->register($this->action('same'));

        $this->expectException(InvalidArgumentException::class);
        $this->registry()->register($this->action('same'));
    }

    public function test_a_visibility_closure_receives_the_authenticated_user(): void
    {
        $seen = null;
        $this->registry()->register($this->action('probe', 1, function (Authenticatable $viewer) use (&$seen): bool {
            $seen = $viewer;

            return true;
        }));
        $manager = $this->managerOf($this->a);

        $this->registry()->actionsFor($manager, $this->a->id);

        $this->assertInstanceOf(User::class, $seen);
        $this->assertSame($manager->id, $seen->id);
    }

    public function test_actions_show_on_the_store_list_by_rights_with_the_store_in_the_query(): void
    {
        $manager = $this->managerOf($this->a);
        $this->registry()->register($this->action('stats'));
        $this->registry()->register($this->action('never', 1, fn (): bool => false));
        $this->registry()->register($this->action('alpha-only', 2, fn (Authenticatable $viewer, int $id): bool => $id === $this->a->id));

        $this->actingAs($manager);
        Livewire::test('pages::stores.index')
            ->assertSeeHtml('data-test="store-action-stats"')
            ->assertSeeHtml('data-test="store-action-alpha-only"')
            ->assertSeeHtml('?store='.$this->a->id)
            ->assertDontSeeHtml('data-test="store-action-never"');

        $this->actingAs($this->owner());
        $html = Livewire::test('pages::stores.index')->html();
        $this->assertSame(2, substr_count($html, 'data-test="store-action-stats"'));
        $this->assertSame(1, substr_count($html, 'data-test="store-action-alpha-only"'));
        $this->assertStringContainsString('?store='.$this->b->id, $html);
    }

    public function test_the_list_without_actions_still_renders(): void
    {
        $this->actingAs($this->managerOf($this->a));

        Livewire::test('pages::stores.index')->assertSee('Alpha')->assertDontSeeHtml('data-test="store-action-');
    }
}
