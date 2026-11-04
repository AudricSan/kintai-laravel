<?php

namespace Tests\Feature\Ical;

use App\Actions\AnonymizeUser;
use App\Actions\Ical\DeleteIcalToken;
use App\Actions\Ical\GenerateIcalToken;
use App\Models\ActivityEntry;
use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class IcalTokenTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->employee = $this->employeeOf($this->store);
    }

    private function feedStatus(string $token, ?Store $store = null): int
    {
        return $this->get('/ical/'.$token.'/'.($store ?? $this->store)->id.'/shifts.ics')->getStatusCode();
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $token = app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        $this->assertSame(64, strlen($token));
        $row = DB::table('ical_tokens')->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode((array) $row));
        $this->assertSame(200, $this->feedStatus($token));
    }

    public function test_regenerating_invalidates_the_previous_token(): void
    {
        $generate = app(GenerateIcalToken::class);
        $first = $generate->handle($this->employee, $this->employee, $this->store);
        $second = $generate->handle($this->employee, $this->employee, $this->store);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, IcalToken::count());
        $this->assertSame(404, $this->feedStatus($first));
        $this->assertSame(200, $this->feedStatus($second));
    }

    public function test_deleting_the_link_makes_the_feed_unreachable(): void
    {
        $token = app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        app(DeleteIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        $this->assertSame(0, IcalToken::count());
        $this->assertSame(404, $this->feedStatus($token));
    }

    public function test_the_links_of_an_employee_are_per_store(): void
    {
        $second = Store::factory()->create();
        $second->members()->attach($this->employee);
        $generate = app(GenerateIcalToken::class);

        $a = $generate->handle($this->employee, $this->employee, $this->store);
        $b = $generate->handle($this->employee, $this->employee, $second);

        $this->assertSame(2, IcalToken::count());
        $this->assertSame(404, $this->feedStatus($a, $second));
        $this->assertSame(200, $this->feedStatus($b, $second));
    }

    public function test_an_employee_cannot_get_a_link_for_a_store_they_do_not_belong_to(): void
    {
        $this->expectException(ValidationException::class);

        app(GenerateIcalToken::class)->handle($this->employee, $this->employee, Store::factory()->create());
    }

    public function test_a_manager_regenerates_and_deletes_the_link_of_an_employee_of_their_store(): void
    {
        $manager = $this->managerOf($this->store);
        $old = app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        $new = app(GenerateIcalToken::class)->handle($manager, $this->employee, $this->store);
        $this->assertSame(404, $this->feedStatus($old));
        $this->assertSame(200, $this->feedStatus($new));

        app(DeleteIcalToken::class)->handle($manager, $this->employee, $this->store);
        $this->assertSame(404, $this->feedStatus($new));
    }

    public function test_a_manager_of_another_store_cannot_touch_the_link(): void
    {
        $outsider = $this->managerOf(Store::factory()->create());

        $this->expectException(AuthorizationException::class);

        app(GenerateIcalToken::class)->handle($outsider, $this->employee, $this->store);
    }

    public function test_an_employee_cannot_touch_a_colleague_link(): void
    {
        $colleague = $this->employeeOf($this->store);
        app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        try {
            app(DeleteIcalToken::class)->handle($colleague, $this->employee, $this->store);
            $this->fail('A colleague deleted the link.');
        } catch (AuthorizationException) {
            $this->assertSame(1, IcalToken::count());
        }
    }

    public function test_a_manager_cannot_manage_the_link_of_an_owner(): void
    {
        $owner = $this->owner();
        $this->store->members()->attach($owner);

        $this->expectException(AuthorizationException::class);

        app(GenerateIcalToken::class)->handle($this->managerOf($this->store), $owner, $this->store);
    }

    public function test_actions_are_logged_without_the_token(): void
    {
        $manager = $this->managerOf($this->store);
        $token = app(GenerateIcalToken::class)->handle($manager, $this->employee, $this->store);
        app(DeleteIcalToken::class)->handle($manager, $this->employee, $this->store);

        $entries = ActivityEntry::query()->orderBy('id')->get();
        $this->assertSame(['ical.token_regenerated', 'ical.token_deleted'], $entries->pluck('action')->all());
        $this->assertSame($manager->id, $entries[0]->actor_id);
        $this->assertSame($this->store->id, $entries[0]->store_id);
        $this->assertStringNotContainsString($token, json_encode($entries->toArray()));
    }

    public function test_anonymizing_an_employee_deletes_their_links(): void
    {
        $token = app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        app(AnonymizeUser::class)->handle($this->employee);

        $this->assertSame(0, IcalToken::count());
        $this->assertSame(404, $this->feedStatus($token));
    }

    public function test_the_calendar_page_shows_the_link_once_and_manages_it(): void
    {
        $this->actingAs($this->employee);

        $page = Livewire::test('pages::settings.calendar')
            ->assertSee(__('No link yet.'))
            ->call('generate', $this->store->id);

        $url = $page->get('revealed')[$this->store->id];
        $this->assertMatchesRegularExpression('#/ical/[0-9a-f]{64}/'.$this->store->id.'/shifts\.ics$#', $url);
        $page->assertSee($url);

        // Le lien fonctionne, et seule son empreinte est en base.
        preg_match('#/ical/([0-9a-f]{64})/#', $url, $matches);
        $this->assertSame(hash('sha256', $matches[1]), IcalToken::first()->token_hash);
        $this->assertSame(200, $this->feedStatus($matches[1]));

        // Une nouvelle visite ne le montre plus.
        Livewire::test('pages::settings.calendar')
            ->assertDontSee($matches[1])
            ->assertSee(__('A link is active. For security it cannot be shown again: generate a new one if you lost it.'))
            ->call('remove', $this->store->id);

        $this->assertSame(0, IcalToken::count());
        $this->assertSame(404, $this->feedStatus($matches[1]));
    }

    public function test_the_calendar_page_refuses_a_store_the_user_does_not_belong_to(): void
    {
        $this->actingAs($this->employee);

        Livewire::test('pages::settings.calendar')
            ->call('generate', Store::factory()->create()->id)
            ->assertStatus(404);

        $this->assertSame(0, IcalToken::count());
    }

    public function test_the_calendar_section_only_shows_for_users_with_a_store(): void
    {
        $this->actingAs($this->employee)->get(route('calendar.edit'))->assertOk();
        $this->actingAs($this->employee)->get(route('profile.edit'))->assertSee(route('calendar.edit'));

        $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertDontSee(route('calendar.edit'));
    }

    public function test_a_manager_regenerates_the_link_from_the_employee_page(): void
    {
        $this->actingAs($this->managerOf($this->store));
        $old = app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        $page = Livewire::test('pages::employees.edit', ['user' => $this->employee])
            ->call('regenerateIcal', $this->store->id);

        $url = $page->get('revealedIcal')[$this->store->id];
        preg_match('#/ical/([0-9a-f]{64})/#', $url, $matches);
        $this->assertNotSame($old, $matches[1]);
        $this->assertSame(404, $this->feedStatus($old));
        $this->assertSame(200, $this->feedStatus($matches[1]));

        $page->call('deleteIcal', $this->store->id);
        $this->assertSame(0, IcalToken::count());
    }

    public function test_regenerating_and_deleting_go_through_a_flux_confirmation_modal(): void
    {
        $this->actingAs($this->employee);
        $old = app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        $page = Livewire::test('pages::settings.calendar')
            ->call('confirmRegenerate', $this->store->id)
            ->assertSet('pendingStoreId', $this->store->id)
            ->assertSee('confirm-ical-regenerate');
        // Rien n'a changé tant que la modale n'est pas confirmée.
        $this->assertSame(200, $this->feedStatus($old));
        $this->assertStringNotContainsString('wire:confirm', $page->html());

        $page->call('regeneratePending');
        $this->assertSame(404, $this->feedStatus($old));
        $this->assertCount(1, $page->get('revealed'));

        $page->call('confirmRemove', $this->store->id)->assertSet('pendingStoreId', $this->store->id);
        $this->assertSame(1, IcalToken::count());
        $page->call('removePending');
        $this->assertSame(0, IcalToken::count());
    }

    public function test_the_manager_confirmation_modals_act_on_the_chosen_store(): void
    {
        $this->actingAs($this->managerOf($this->store));
        app(GenerateIcalToken::class)->handle($this->employee, $this->employee, $this->store);

        $page = Livewire::test('pages::employees.edit', ['user' => $this->employee])
            ->call('confirmRegenerateIcal', $this->store->id)
            ->assertSet('pendingIcalStoreId', $this->store->id);
        $this->assertStringNotContainsString('The previous link will stop working. Continue?', $page->html());
        $page->call('regeneratePendingIcal');
        $this->assertCount(1, $page->get('revealedIcal'));

        $page->call('confirmDeleteIcal', $this->store->id)->call('deletePendingIcal');
        $this->assertSame(0, IcalToken::count());
    }

    public function test_a_manager_without_update_rights_does_not_get_the_buttons(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['employees.view']), $this->store);
        $this->actingAs($viewer);

        Livewire::test('pages::employees.edit', ['user' => $this->employee])
            ->assertDontSee('ical-regenerate-'.$this->store->id)
            ->call('regenerateIcal', $this->store->id)
            ->assertForbidden();
    }
}
