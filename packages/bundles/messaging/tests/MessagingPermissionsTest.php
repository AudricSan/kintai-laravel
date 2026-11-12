<?php

namespace Kintai\Bundles\Messaging\Tests;

use App\Authorization\Authorizer;
use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\Role;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

// Le fond commun n'est pas une classe de test : le chargeur de PHPUnit ne le trouve pas seul.
require_once __DIR__.'/PermissionFixtures.php';

/**
 * Permissions `messaging.view/send/create` : droits par défaut des rôles, mise à niveau d'une installation existante, et
 * refus sur les pages, les composants (actions comprises), le menu, le badge et le widget. L'API est dans
 * `MessagingPermissionsApiTest`. Une permission ne donne jamais accès à la conversation d'autrui.
 */
class MessagingPermissionsTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use PermissionFixtures;
    use RefreshDatabase;

    private const MIGRATION = '/database/migrations/2026_11_07_000002_grant_messaging_permissions_to_existing_roles.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMessaging();
    }

    // -- Déclaration et droits par défaut ------------------------------------------------------------------------

    public function test_the_bundle_gives_the_three_permissions_to_managers_and_employees_and_the_owner_has_them(): void
    {
        $bundle = app(BundleManager::class)->find('messaging');
        $this->assertNotNull($bundle);
        $this->assertEqualsCanonicalizing($this->allPermissions(), $bundle->defaultPermissions()['manager']);
        $this->assertEqualsCanonicalizing($this->allPermissions(), $bundle->defaultPermissions()['employee']);

        foreach (['manager', 'employee'] as $slug) {
            $this->assertEqualsCanonicalizing(
                $this->allPermissions(),
                $this->role($slug)->permissions()->where('permission', 'like', 'messaging.%')->pluck('permission')->all(),
                $slug,
            );
        }

        $employee = $this->employeeOf($this->a);
        $manager = $this->managerOf($this->a);
        $owner = $this->owner();

        foreach ($this->allPermissions() as $permission) {
            $this->assertTrue(Gate::forUser($employee)->allows($permission, $this->a), $permission);
            $this->assertTrue(Gate::forUser($manager)->allows($permission, $this->a), $permission);
            $this->assertTrue(Gate::forUser($owner)->allows($permission, $this->a), $permission);
        }
    }

    public function test_the_owner_can_revoke_a_default_right_and_it_does_not_come_back(): void
    {
        $this->role('employee')->permissions()->where('permission', 'messaging.create')->delete();
        $employee = $this->employeeOf($this->a);

        $this->assertFalse(Gate::forUser($employee)->allows('messaging.create', $this->a));
        $this->assertNull(app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('messaging') ?? throw new LogicException));
        $this->assertFalse(Gate::forUser($employee)->allows('messaging.create', $this->a));
    }

    public function test_the_upgrade_migration_gives_the_three_permissions_to_every_role_of_an_installation_already_using_the_bundle(): void
    {
        $custom = Role::factory()->create();
        $partial = $this->customRole(['messaging.view']);
        $this->role('employee')->permissions()->where('permission', 'like', 'messaging.%')->delete();
        $this->role('manager')->permissions()->where('permission', 'like', 'messaging.%')->delete();

        $migration = require dirname(__DIR__).self::MIGRATION;
        $migration->up();

        foreach ([$custom, $partial, $this->role('employee'), $this->role('manager')] as $role) {
            $this->assertEqualsCanonicalizing($this->allPermissions(), $role->permissions()->where('permission', 'like', 'messaging.%')->pluck('permission')->all());
        }
        // Les droits ajoutés valent pour tous les magasins, comme la messagerie libre-service d'avant.
        $this->assertTrue((bool) $custom->permissions()->where('permission', 'messaging.send')->firstOrFail()->all_stores);

        // Rejouée, elle ne crée pas de doublon.
        $migration->up();
        $this->assertSame(3, $custom->permissions()->count());
        $this->assertSame(3, $partial->permissions()->count());
    }

    public function test_the_upgrade_migration_does_nothing_while_the_bundle_was_never_activated(): void
    {
        $custom = Role::factory()->create();
        app(AppSettings::class)->set(DefaultPermissionGrant::SETTING, '[]');

        (require dirname(__DIR__).self::MIGRATION)->up();

        $this->assertSame(0, $custom->permissions()->count());
    }

    // -- Routes et composants ------------------------------------------------------------------------------------

    public function test_the_pages_need_their_permission(): void
    {
        $none = $this->userWith([]);
        $viewer = $this->userWith(['messaging.view']);
        $creator = $this->userWith(['messaging.create']);
        $thread = $this->threadTo($viewer);

        foreach (['/messaging', '/messaging/compose', "/messaging/threads/{$thread->id}"] as $url) {
            $this->actingAs($none)->get($url)->assertForbidden();
        }

        $this->actingAs($viewer)->get('/messaging')->assertOk();
        $this->actingAs($viewer)->get("/messaging/threads/{$thread->id}")->assertOk();
        $this->actingAs($viewer)->get('/messaging/compose')->assertForbidden();
        $this->actingAs($creator)->get('/messaging/compose')->assertOk();
        $this->actingAs($creator)->get('/messaging')->assertForbidden();
    }

    public function test_the_components_refuse_mounting_without_permission(): void
    {
        $none = $this->userWith([]);
        $viewer = $this->userWith(['messaging.view']);
        $thread = $this->threadTo($viewer);
        $participant = $this->userWith([]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $participant->id, 'is_read' => false]);

        Livewire::actingAs($none)->test('messaging.index')->assertForbidden();
        Livewire::actingAs($none)->test('messaging.compose')->assertForbidden();
        Livewire::actingAs($viewer)->test('messaging.compose')->assertForbidden();
        // Participant sans permission : refusé ; non participant : introuvable.
        Livewire::actingAs($participant)->test('messaging.thread', ['id' => $thread->id])->assertForbidden();
        Livewire::actingAs($none)->test('messaging.thread', ['id' => $thread->id])->assertNotFound();
    }

    public function test_the_compose_form_starts_a_conversation_with_the_create_permission(): void
    {
        $creator = $this->userWith(['messaging.create']);
        $colleague = $this->employeeOf($this->a);

        Livewire::actingAs($creator)->test('messaging.compose')
            ->set('recipientIds', [(string) $colleague->id])->set('subject', 'Hi')->set('body', 'Hello')
            ->call('send')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(1, MessageThread::count());
    }

    public function test_starting_a_conversation_needs_the_create_permission_even_when_called_directly(): void
    {
        $writer = $this->userWith(['messaging.view', 'messaging.send']);
        $colleague = $this->employeeOf($this->a);

        $this->expectException(AuthorizationException::class);
        $this->actions()->start($writer, $this->a->id, 'Subject', 'Hello', [$colleague->id]);
    }

    public function test_the_create_permission_is_scoped_to_the_store(): void
    {
        $user = $this->userWith(['messaging.view', 'messaging.create'], $this->a);
        $this->b->members()->attach($user);
        $colleagueB = $this->employeeOf($this->b);

        $this->assertSame([$this->a->id], array_keys(app(MessagingAccess::class)->composeStores($user)));

        try {
            $this->actions()->start($user, $this->b->id, 'Subject', 'Hello', [$colleagueB->id]);
            $this->fail('A store without the permission must be refused.');
        } catch (AuthorizationException) {
            $this->assertSame(0, MessageThread::count());
        }
    }

    public function test_replying_needs_the_send_permission(): void
    {
        $reader = $this->userWith(['messaging.view']);
        $thread = $this->threadTo($reader);

        // Le formulaire de réponse disparaît, et l'action elle-même est refusée.
        Livewire::actingAs($reader)->test('messaging.thread', ['id' => $thread->id])->assertOk()->assertDontSee('Your reply...');
        Livewire::actingAs($reader)->test('messaging.thread', ['id' => $thread->id])->set('body', 'Sneaky')->call('reply')->assertForbidden();
        $this->assertSame(1, ThreadMessage::count());

        // Avec la permission, la réponse passe.
        $writer = $this->userWith(['messaging.view', 'messaging.send']);
        $own = $this->threadTo($writer);
        Livewire::actingAs($writer)->test('messaging.thread', ['id' => $own->id])->assertSee('Your reply...')->set('body', 'Thanks')->call('reply')->assertHasNoErrors();
        $this->assertSame(2, $own->messages()->count());
    }

    public function test_a_user_who_lost_the_view_permission_cannot_act_on_a_conversation_he_belongs_to(): void
    {
        $user = $this->employeeOf($this->a, ['name' => 'Bob']);
        $thread = $this->threadTo($user);
        $message = $this->actions()->reply($user, $thread, 'Mine');

        $this->role('employee')->permissions()->where('permission', 'messaging.view')->delete();
        app(Authorizer::class)->forget();

        Livewire::actingAs($user)->test('messaging.thread', ['id' => $thread->id])->assertForbidden();
        Livewire::actingAs($user)->test('messaging.index')->assertForbidden();
        $this->actingAs($user)->get("/messaging/threads/{$thread->id}")->assertForbidden();

        foreach ([
            fn () => $this->actions()->markRead($user, $thread),
            fn () => $this->actions()->hide($user, $thread),
            fn () => $this->actions()->deleteMessage($user, $message),
        ] as $call) {
            try {
                $call();
                $this->fail('The action must be refused without messaging.view.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(2, ThreadMessage::count());
        $this->assertNull(ThreadParticipant::where('user_id', $user->id)->firstOrFail()->hidden_at);
    }

    public function test_hiding_a_conversation_and_deleting_a_message_work_with_the_view_permission(): void
    {
        $viewer = $this->userWith(['messaging.view', 'messaging.send']);
        $thread = $this->threadTo($viewer);
        $message = $this->actions()->reply($viewer, $thread, 'Mine');

        Livewire::actingAs($viewer)->test('messaging.thread', ['id' => $thread->id])
            ->call('confirmMessageDeletion', $message->id)->call('deleteMessage')->assertHasNoErrors();
        $this->assertFalse(ThreadMessage::whereKey($message->id)->exists());

        Livewire::actingAs($viewer)->test('messaging.index')->call('confirmHiding', $thread->id)->call('hide')->assertHasNoErrors();
        $this->assertNotNull(ThreadParticipant::where('thread_id', $thread->id)->where('user_id', $viewer->id)->firstOrFail()->hidden_at);
    }

    // -- Étanchéité : la permission ne donne pas la conversation d'autrui -----------------------------------------

    public function test_all_permissions_never_give_access_to_the_conversation_of_others(): void
    {
        $alice = $this->employeeOf($this->a, ['name' => 'Alice']);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob']);
        $thread = $this->actions()->start($alice, $this->a->id, 'Private', 'Secret', [$bob->id]);

        foreach ([$this->owner(), $this->managerOf($this->a), $this->userWith($this->allPermissions(), $this->a, true)] as $user) {
            $this->actingAs($user)->get("/messaging/threads/{$thread->id}")->assertNotFound();
            Livewire::actingAs($user)->test('messaging.thread', ['id' => $thread->id])->assertNotFound();
            $this->assertSame(0, app(MessagingAccess::class)->unreadCount($user));
        }

        $this->assertSame(1, ThreadMessage::count());
    }

    public function test_the_view_permission_of_one_store_does_not_open_the_conversations_of_another(): void
    {
        $user = $this->userWith(['messaging.view', 'messaging.send'], $this->a);
        $this->b->members()->attach($user);
        $this->threadTo($user);
        // L'utilisateur participe aussi à une conversation du magasin Beta, où il n'a aucun droit.
        $this->actions()->start($this->employeeOf($this->b), $this->b->id, 'Beta', 'Hi', [$user->id]);
        $foreign = MessageThread::where('store_id', $this->b->id)->firstOrFail();

        $this->actingAs($user)->get("/messaging/threads/{$foreign->id}")->assertForbidden();
        Livewire::actingAs($user)->test('messaging.thread', ['id' => $foreign->id])->assertForbidden();
        Livewire::actingAs($user)->test('messaging.index')->assertSee('Subject')->assertDontSee('Beta');
        $this->assertSame(1, app(MessagingAccess::class)->unreadCount($user));
    }

    // -- Menu, badge, widget -------------------------------------------------------------------------------------

    public function test_the_menu_entry_the_badge_and_the_widgets_need_the_view_permission(): void
    {
        $viewer = $this->userWith(['messaging.view']);
        $writerOnly = $this->userWith(['messaging.create', 'messaging.send']);
        $thread = $this->threadTo($viewer);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $writerOnly->id, 'is_read' => false]);
        $nav = app(NavigationRegistry::class);

        $item = collect($nav->visibleTo($viewer))->first(fn ($i) => $i->key === 'messaging');
        $this->assertNotNull($item);
        $this->assertSame(1, $item->badgeFor($viewer));
        $this->assertNull(collect($nav->visibleTo($writerOnly))->first(fn ($i) => $i->key === 'messaging'));
        $this->assertSame(0, app(MessagingAccess::class)->unreadCount($writerOnly));

        $this->actingAs($viewer)->get(route('dashboard'))->assertSee(route('messaging.index'), false);
        $this->actingAs($writerOnly)->get(route('dashboard'))->assertDontSee(route('messaging.index'), false);

        foreach (['employee', 'manager'] as $audience) {
            $widget = app(DashboardWidgetRegistry::class)->get("messaging.{$audience}");
            $this->assertNotNull($widget);
            $this->assertSame('messaging.view', $widget->permission);
            $this->assertTrue(($widget->when)($viewer));
            $this->assertFalse(($widget->when)($writerOnly));
        }
    }
}
