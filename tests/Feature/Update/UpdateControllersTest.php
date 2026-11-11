<?php

namespace Tests\Feature\Update;

use App\Models\ActivityEntry;
use App\Models\User;
use App\Settings\AppSettings;
use App\Update\UpdateApplier;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class UpdateControllersTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
    }

    private function state(string $phase): UpdateState
    {
        $state = new UpdateState('unused.json');
        $state->phase = $phase;
        $state->from = '1.0.0';
        $state->to = '1.1.0';
        $state->swapComplete = true;

        return $state;
    }

    /** @param  callable(MockInterface): void|null  $expectations */
    private function applier(string $phase, ?callable $expectations = null): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock) use ($phase, $expectations): void {
            $mock->shouldReceive('state')->andReturn($this->state($phase));

            if ($expectations !== null) {
                $expectations($mock);
            }
        });
    }

    public function test_apply_without_the_password_is_refused(): void
    {
        $this->applier(UpdateState::STAGED, fn (MockInterface $mock) => $mock->shouldReceive('apply')->never());

        $this->post(route('updates.apply'))->assertRedirect(route('updates.index'))->assertSessionHas('update_error', 'The password is incorrect.');
        $this->post(route('updates.apply'), ['password' => 'wrong'])->assertRedirect(route('updates.index'))->assertSessionHas('update_error', 'The password is incorrect.');

        $this->assertSame(0, ActivityEntry::where('action', 'update.applied')->count());
    }

    public function test_apply_logs_then_redirects_to_the_finish_page(): void
    {
        $this->applier(UpdateState::STAGED, function (MockInterface $mock): void {
            $mock->shouldReceive('apply')->once()->andReturnUsing(function (): void {
                // L'audit est écrit AVANT l'application (le code change sur le disque pendant apply()).
                $this->assertSame(1, ActivityEntry::where('action', 'update.applied')->count());
            });
        });

        $this->post(route('updates.apply'), ['password' => 'password'])->assertRedirect(route('updates.finish.show'));

        $entry = ActivityEntry::where('action', 'update.applied')->firstOrFail();
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame(['from' => '1.0.0', 'to' => '1.1.0'], $entry->properties);
        $this->assertSame(0, ActivityEntry::where('action', 'update.rolled_back')->count());
    }

    public function test_apply_failure_goes_back_to_the_screen_with_the_message(): void
    {
        $this->applier(UpdateState::STAGED, fn (MockInterface $mock) => $mock->shouldReceive('apply')->once()->andThrow(new UpdateException('The replacement of the files failed and was rolled back.')));

        $this->post(route('updates.apply'), ['password' => 'password'])
            ->assertRedirect(route('updates.index'))
            ->assertSessionHas('update_error', 'The replacement of the files failed and was rolled back.');

        $this->assertSame(1, ActivityEntry::where('action', 'update.rolled_back')->count());
    }

    public function test_apply_requires_a_prepared_update(): void
    {
        $this->applier(UpdateState::IDLE, fn (MockInterface $mock) => $mock->shouldReceive('apply')->never());

        $this->post(route('updates.apply'), ['password' => 'password'])
            ->assertRedirect(route('updates.index'))
            ->assertSessionHas('update_error', 'There is no prepared update to apply.');

        $this->assertSame(0, ActivityEntry::where('action', 'update.applied')->count());
    }

    public function test_the_finish_page_is_standalone_without_livewire(): void
    {
        $this->applier(UpdateState::SWAPPED);

        $response = $this->get(route('updates.finish.show'))->assertOk();

        $response->assertSee('Update in progress...')
            ->assertSee(route('updates.finish'))
            ->assertSee('data-test="update-finish-button"', false)
            ->assertSee('<noscript>', false)
            ->assertSee("getElementById('finish-form').submit()", false)
            ->assertSee('name="_token"', false)
            // Aucun composant : ni identifiant ni instantané Livewire. (Les styles de Livewire peuvent être injectés par un
            // composant rendu plus tôt dans le même processus de test ; ce n'est pas le cas d'une vraie requête.)
            ->assertDontSee('wire:id', false)
            ->assertDontSee('wire:snapshot', false)
            ->assertDontSee('wire:click', false);

        $this->assertStringNotContainsString('wire:', (string) file_get_contents(resource_path('views/updates/finishing.blade.php')));
        $this->assertStringNotContainsString('<livewire', (string) file_get_contents(resource_path('views/updates/finishing.blade.php')));
    }

    public function test_the_finish_page_redirects_when_nothing_is_pending(): void
    {
        $this->applier(UpdateState::IDLE);

        $this->get(route('updates.finish.show'))->assertRedirect(route('updates.index'));
    }

    public function test_finish_succeeds(): void
    {
        $this->applier(UpdateState::SWAPPED, fn (MockInterface $mock) => $mock->shouldReceive('finish')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'migrations_output' => '']));

        $this->post(route('updates.finish'))
            ->assertRedirect(route('updates.index'))
            ->assertSessionHas('update_success', 'Kintai was updated from 1.0.0 to 1.1.0.');

        $this->assertSame(0, ActivityEntry::where('action', 'update.rolled_back')->count());
    }

    public function test_finish_failure_reports_the_rollback(): void
    {
        $this->applier(UpdateState::SWAPPED, fn (MockInterface $mock) => $mock->shouldReceive('finish')->once()->andThrow(new UpdateException('The update failed and was rolled back. The migrations failed.')));

        $this->post(route('updates.finish'))
            ->assertRedirect(route('updates.index'))
            ->assertSessionHas('update_error', 'The update failed and was rolled back. The migrations failed.');

        $entry = ActivityEntry::where('action', 'update.rolled_back')->firstOrFail();
        $this->assertSame('1.0.0', $entry->properties['from']);
        $this->assertSame('1.1.0', $entry->properties['to']);
    }

    public function test_the_routes_are_open_to_the_owner_during_maintenance(): void
    {
        $this->app->make(AppSettings::class)->set('maintenance.enabled', true);
        $this->applier(UpdateState::SWAPPED, fn (MockInterface $mock) => $mock->shouldReceive('finish')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'migrations_output' => '']));

        $this->get(route('updates.finish.show'))->assertOk();
        $this->post(route('updates.finish'))->assertRedirect(route('updates.index'));
        $this->get(route('updates.index'))->assertOk();
    }

    public function test_the_routes_stay_closed_to_others_during_maintenance(): void
    {
        $this->app->make(AppSettings::class)->set('maintenance.enabled', true);
        $this->applier(UpdateState::SWAPPED, fn (MockInterface $mock) => $mock->shouldReceive('finish')->never());

        $this->actingAs(User::factory()->create());

        $this->get(route('updates.finish.show'))->assertStatus(503);
        $this->post(route('updates.finish'))->assertStatus(503);
        $this->post(route('updates.apply'))->assertStatus(503);
    }

    public function test_the_routes_use_the_web_group_with_csrf_and_the_owner_right(): void
    {
        foreach (['updates.apply', 'updates.finish', 'updates.finish.show'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('web', $middleware, $name);
            $this->assertContains('auth', $middleware, $name);
            $this->assertContains('can:manage-settings', $middleware, $name);
        }

        $this->assertSame(['POST'], app('router')->getRoutes()->getByName('updates.apply')->methods());
        $this->assertSame(['POST'], app('router')->getRoutes()->getByName('updates.finish')->methods());
    }
}
