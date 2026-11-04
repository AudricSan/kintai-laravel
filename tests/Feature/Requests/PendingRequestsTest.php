<?php

namespace Tests\Feature\Requests;

use App\Models\User;
use App\Requests\PendingRequestProvider;
use App\Requests\PendingRequestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class PendingRequestsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private function provider(string $key, int $count, string $label = 'Time off'): PendingRequestProvider
    {
        return new PendingRequestProvider($key, $label, 'requests.index', fn (User $user): int => $count);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('requests.index'))->assertRedirect(route('login'));
    }

    public function test_page_shows_an_empty_state_without_provider(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('requests.index'))->assertOk()->assertSee('No request source is available yet.');
    }

    public function test_page_lists_providers_with_their_count(): void
    {
        $registry = app(PendingRequestRegistry::class);
        $registry->register($this->provider('timeoff', 3));
        $registry->register($this->provider('swaps', 0, 'Shift swaps'));
        $this->actingAs(User::factory()->create());

        $this->get(route('requests.index'))
            ->assertOk()
            ->assertSee('Time off')
            ->assertSee('Shift swaps')
            ->assertDontSee('No request source is available yet.');

        $this->assertSame(3, $registry->total(auth()->user()));
    }

    public function test_duplicate_provider_is_refused(): void
    {
        $registry = app(PendingRequestRegistry::class);
        $registry->register($this->provider('timeoff', 1));

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->provider('timeoff', 2));
    }

    public function test_a_failing_provider_counts_zero_and_is_reported(): void
    {
        Exceptions::fake();
        $registry = app(PendingRequestRegistry::class);
        $registry->register(new PendingRequestProvider('broken', 'Broken', 'requests.index', fn (User $user): int => throw new RuntimeException('boom')));
        $registry->register($this->provider('ok', 2));
        $this->actingAs(User::factory()->create());

        $this->get(route('requests.index'))->assertOk();

        $this->assertSame(2, $registry->total(auth()->user()));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_widget_shows_the_total_and_an_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('widgets::manager.pending-requests')->assertSee('No request source is available yet.');

        app(PendingRequestRegistry::class)->register($this->provider('timeoff', 4));

        Livewire::test('widgets::manager.pending-requests')->assertSeeInOrder(['Pending requests', '4']);
    }
}
