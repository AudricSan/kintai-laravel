<?php

namespace Kintai\Bundles\Messaging\Tests;

use App\Actions\AnonymizeUser;
use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\MessagingActions;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Messagerie : confidentialité (seuls les participants lisent), destinataires limités aux collègues du magasin,
 * non-lus, conversation retirée de sa seule liste, suppression de ses seuls messages, échappement, notifications,
 * journal sans contenu, anonymisation, bundle désactivé.
 */
class MessagingTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
    }

    /**
     * Active le bundle comme le fait l'Owner : les droits par défaut des rôles manager et employee sont accordés.
     */
    private function enableMessaging(): void
    {
        $this->enableBundle('messaging');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('messaging') ?? throw new LogicException);
    }

    private function actions(): MessagingActions
    {
        return app(MessagingActions::class);
    }

    /**
     * @return array{User, User, User} Alice, Bob et Carl, membres du magasin Alpha.
     */
    private function trio(): array
    {
        $this->enableMessaging();

        return [
            $this->employeeOf($this->a, ['name' => 'Alice Sender']),
            $this->employeeOf($this->a, ['name' => 'Bob Reader']),
            $this->employeeOf($this->a, ['name' => 'Carl Reader']),
        ];
    }

    private function notified(User $user): int
    {
        return $user->notifications()->where('type', 'message_received')->count();
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableMessaging();

        $this->assertTrue(app(StoreFeatureRegistry::class)->has('messages'));
        $this->assertTrue(app(NavigationRegistry::class)->has('messaging'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('message_received'));
        $this->assertTrue(app(ActivityActionRegistry::class)->has('message.sent'));
        $this->assertTrue(app(DashboardWidgetRegistry::class)->has('messaging.employee'));
        $this->assertTrue(app(DashboardWidgetRegistry::class)->has('messaging.manager'));
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('messages'));
        $this->assertFalse(app(NavigationRegistry::class)->has('messaging'));
        $this->actingAs($this->employeeOf($this->a))->get('/messaging')->assertNotFound();
        $this->assertTrue(Schema::hasTable('thread_messages'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableMessaging();

        $this->get('/messaging')->assertRedirect(route('login'));
    }

    // -- Envoyer -------------------------------------------------------------------------------------------------

    public function test_an_employee_starts_a_conversation_with_the_default_rights(): void
    {
        [$alice, $bob, $carl] = $this->trio();

        Livewire::actingAs($alice)->test('messaging.compose')
            ->assertSee('Bob Reader')
            ->set('recipientIds', [(string) $bob->id, (string) $carl->id])
            ->set('subject', '  Inventory  ')->set('body', "Please count\nthe shelves")
            ->call('send')->assertHasNoErrors()->assertRedirect();

        $thread = MessageThread::firstOrFail();
        $this->assertSame([$this->a->id, $alice->id, 'Inventory'], [$thread->store_id, $thread->creator_id, $thread->subject]);
        $this->assertSame("Please count\nthe shelves", ThreadMessage::firstOrFail()->body);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id, $carl->id], ThreadParticipant::pluck('user_id')->all());
        $this->assertTrue(ThreadParticipant::where('user_id', $alice->id)->firstOrFail()->is_read);
        $this->assertFalse(ThreadParticipant::where('user_id', $bob->id)->firstOrFail()->is_read);

        // Notification : destinataires seulement, dans leur langue, jamais l'auteur.
        $this->assertSame([0, 1, 1], [$this->notified($alice), $this->notified($bob), $this->notified($carl)]);
        $notification = $bob->notifications()->where('type', 'message_received')->firstOrFail();
        $this->assertSame($thread->id, $notification->data['reference_id']);
        $this->assertSame('message_thread', $notification->data['reference_type']);
        $this->assertSame('/messaging/threads/'.$thread->id, $notification->data['link']);
    }

    public function test_the_activity_log_never_keeps_the_content_of_a_message(): void
    {
        [$alice, $bob] = $this->trio();

        $thread = $this->actions()->start($alice, $this->a->id, 'Secret subject', 'Secret body', [$bob->id]);
        $this->actions()->reply($bob, $thread, 'Secret answer');

        $entries = ActivityEntry::whereIn('action', ['message.sent', 'message.replied'])->get();
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('Secret', (string) json_encode($entry->toArray()));
        }
    }

    public function test_recipients_are_limited_to_the_colleagues_of_the_store(): void
    {
        [$alice, $bob] = $this->trio();
        $stranger = $this->employeeOf($this->b, ['name' => 'Dora Stranger']);
        $inactive = $this->employeeOf($this->a, ['is_active' => false]);

        Livewire::actingAs($alice)->test('messaging.compose')->assertDontSee('Dora Stranger');

        foreach ([[$stranger->id], [$inactive->id], [$bob->id, $stranger->id]] as $recipients) {
            try {
                $this->actions()->start($alice, $this->a->id, 'Hi', 'Hello', $recipients);
                $this->fail('A recipient outside the store was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('recipientIds', $e->errors());
            }
        }

        $this->assertSame(0, MessageThread::count());
    }

    public function test_a_store_where_the_sender_is_not_a_member_is_refused(): void
    {
        [$alice] = $this->trio();
        $other = $this->employeeOf($this->b);

        $this->expectException(ValidationException::class);
        $this->actions()->start($alice, $this->b->id, 'Hi', 'Hello', [$other->id]);
    }

    public function test_the_store_feature_switch_closes_the_store_to_new_messages(): void
    {
        [$alice, $bob] = $this->trio();
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'messages', 'enabled' => false]);

        $this->actingAs($alice)->get('/messaging/compose')->assertNotFound();

        $this->expectException(ValidationException::class);
        $this->actions()->start($alice, $this->a->id, 'Hi', 'Hello', [$bob->id]);
    }

    public function test_the_feature_switch_keeps_old_conversations_readable_but_not_writable(): void
    {
        [$alice, $bob] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Hi', 'Hello', [$bob->id]);
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'messages', 'enabled' => false]);

        $this->actingAs($bob)->get('/messaging/threads/'.$thread->id)->assertOk()->assertSee('Hello');

        $this->expectException(ValidationException::class);
        $this->actions()->reply($bob, $thread, 'Answer');
    }

    public function test_empty_and_oversized_fields_are_refused(): void
    {
        [$alice, $bob] = $this->trio();

        foreach ([['', 'Body'], ['Subject', '   '], [str_repeat('s', 256), 'Body'], ['Subject', str_repeat('b', 5001)]] as [$subject, $body]) {
            try {
                $this->actions()->start($alice, $this->a->id, $subject, $body, [$bob->id]);
                $this->fail('An invalid message was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, MessageThread::count());
    }

    // -- Confidentialité -----------------------------------------------------------------------------------------

    public function test_only_participants_read_a_conversation_whatever_their_role(): void
    {
        [$alice, $bob, $carl] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Confidential', 'For Bob only', [$bob->id]);
        $manager = $this->managerOf($this->a);
        $owner = $this->owner();

        $this->actingAs($bob)->get('/messaging/threads/'.$thread->id)->assertOk()->assertSee('For Bob only');

        foreach ([$carl, $manager, $owner] as $outsider) {
            $this->actingAs($outsider)->get('/messaging/threads/'.$thread->id)->assertNotFound();
            Livewire::actingAs($outsider)->test('messaging.index')->assertDontSee('Confidential');
        }

        $this->assertSame(0, $this->notified($carl));
    }

    public function test_an_outsider_cannot_reply_hide_or_delete(): void
    {
        [$alice, $bob, $carl] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Confidential', 'Hello', [$bob->id]);
        $message = ThreadMessage::firstOrFail();

        $this->actingAs($carl)->get('/messaging/threads/'.$thread->id)->assertNotFound();
        Livewire::actingAs($carl)->test('messaging.thread', ['id' => $thread->id])->assertNotFound();
        Livewire::actingAs($carl)->test('messaging.index')->call('confirmHiding', $thread->id)->assertNotFound();

        $this->assertSame(1, ThreadMessage::count());
        $this->assertNotNull($message->fresh());
    }

    public function test_the_content_is_escaped_when_displayed(): void
    {
        [$alice, $bob] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, '<b>Bold</b> subject', '<script>alert("x")</script>', [$bob->id]);

        $this->actingAs($bob)->get('/messaging/threads/'.$thread->id)
            ->assertOk()->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<b>Bold</b>', false);
        Livewire::actingAs($bob)->test('messaging.index')->assertDontSee('<b>Bold</b>', false)->assertSee('&lt;b&gt;Bold&lt;/b&gt; subject', false);
    }

    // -- Lu / non lu ---------------------------------------------------------------------------------------------

    public function test_the_unread_counter_follows_opened_and_new_conversations(): void
    {
        [$alice, $bob] = $this->trio();
        $threadA = $this->actions()->start($alice, $this->a->id, 'One', 'Hello', [$bob->id]);
        $this->actions()->start($alice, $this->a->id, 'Two', 'Hello again', [$bob->id]);
        $nav = app(NavigationRegistry::class);

        $this->assertSame(2, $this->unread($bob));
        $this->assertSame(0, $this->unread($alice));

        Livewire::actingAs($bob)->test('messaging.thread', ['id' => $threadA->id])->assertOk();

        $this->assertSame(1, $this->unread($bob));
        // Les notifications du fil ouvert sont lues.
        $this->assertSame(0, $bob->unreadNotifications()->where('type', 'message_received')->where('data->reference_id', $threadA->id)->count());
        $this->assertSame(1, $bob->unreadNotifications()->where('type', 'message_received')->count());

        // Le badge du menu reprend le compteur.
        $item = collect($nav->visibleTo($bob))->first(fn ($i) => $i->key === 'messaging');
        $this->assertNotNull($item);
        $this->assertSame(1, $item->badgeFor($bob));

        $this->actions()->reply($alice, $threadA, 'Another one');
        $this->assertSame(2, $this->unread($bob));
    }

    private function unread(User $user): int
    {
        return app(MessagingAccess::class)->unreadCount($user);
    }

    public function test_the_menu_shows_the_unread_badge_only_when_there_is_something_unread(): void
    {
        [$alice, $bob] = $this->trio();

        $this->actingAs($bob)->get('/messaging')->assertOk()->assertSee('data-test="nav-item-messaging"', false)->assertDontSee('data-flux-navlist-badge>', false);

        $this->actions()->start($alice, $this->a->id, 'Subject', 'Hello', [$bob->id]);

        $this->actingAs($bob)->get('/messaging')->assertOk()->assertSee('data-flux-navlist-badge>1', false);
    }

    public function test_the_tabs_filter_the_list(): void
    {
        [$alice, $bob] = $this->trio();
        $this->actions()->start($alice, $this->a->id, 'From Alice', 'Hello', [$bob->id]);
        $this->actions()->start($bob, $this->a->id, 'From Bob', 'Hello', [$alice->id]);

        Livewire::actingAs($bob)->test('messaging.index')->assertSee('From Alice')->assertSee('From Bob')
            ->set('tab', 'sent')->assertDontSee('From Alice')->assertSee('From Bob')
            ->set('tab', 'unread')->assertSee('From Alice')->assertDontSee('From Bob');
    }

    // -- Répondre, retirer, supprimer ----------------------------------------------------------------------------

    public function test_a_reply_notifies_the_others_and_brings_the_conversation_back(): void
    {
        [$alice, $bob, $carl] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Subject', 'Hello', [$bob->id, $carl->id]);
        $this->actions()->hide($carl, $thread);
        $this->assertNotNull(ThreadParticipant::where('user_id', $carl->id)->firstOrFail()->hidden_at);

        Livewire::actingAs($bob)->test('messaging.thread', ['id' => $thread->id])->set('body', 'Got it')->call('reply')->assertHasNoErrors();

        $this->assertSame(['Hello', 'Got it'], ThreadMessage::orderBy('id')->pluck('body')->all());
        $this->assertNull(ThreadParticipant::where('user_id', $carl->id)->firstOrFail()->hidden_at);
        $this->assertFalse(ThreadParticipant::where('user_id', $carl->id)->firstOrFail()->is_read);
        $this->assertTrue(ThreadParticipant::where('user_id', $bob->id)->firstOrFail()->is_read);
        // Alice reçoit la réponse de Bob ; Bob, le message initial ; Carl, le message initial puis la réponse (pas l'auteur).
        $this->assertSame([1, 1, 2], [$this->notified($alice), $this->notified($bob), $this->notified($carl)]);
    }

    public function test_removing_a_conversation_hides_it_for_oneself_only_until_nobody_sees_it(): void
    {
        [$alice, $bob] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Subject', 'Hello', [$bob->id]);

        Livewire::actingAs($bob)->test('messaging.index')->call('confirmHiding', $thread->id)->call('hide');
        Livewire::actingAs($bob)->test('messaging.index')->assertDontSee('Subject');
        Livewire::actingAs($alice)->test('messaging.index')->assertSee('Subject');
        $this->assertSame(0, $this->unread($bob));
        $this->assertNotNull(ActivityEntry::where('action', 'message.thread_hidden')->first());

        $this->assertTrue($this->actions()->hide($alice, $thread));
        $this->assertSame([0, 0, 0], [MessageThread::count(), ThreadMessage::count(), ThreadParticipant::count()]);
        $this->assertNotNull(ActivityEntry::where('action', 'message.thread_deleted')->first());
    }

    public function test_a_user_deletes_only_their_own_messages(): void
    {
        [$alice, $bob] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Subject', 'Mine', [$bob->id]);
        $reply = $this->actions()->reply($bob, $thread, 'Bob answer');
        $first = ThreadMessage::orderBy('id')->firstOrFail();

        try {
            $this->actions()->deleteMessage($bob, $first);
            $this->fail('A message of someone else was deleted.');
        } catch (AuthorizationException) {
            $this->assertNotNull($first->fresh());
        }

        Livewire::actingAs($bob)->test('messaging.thread', ['id' => $thread->id])
            ->call('confirmMessageDeletion', $first->id)->assertNotFound();
        Livewire::actingAs($bob)->test('messaging.thread', ['id' => $thread->id])
            ->call('confirmMessageDeletion', $reply->id)->call('deleteMessage');

        $this->assertNull($reply->fresh());
        $this->assertNotNull(ActivityEntry::where('action', 'message.deleted')->first());
    }

    public function test_a_manager_and_the_owner_cannot_delete_the_messages_of_others(): void
    {
        [$alice, $bob] = $this->trio();
        $manager = $this->managerOf($this->a);
        $this->a->members()->attach($manager);
        $thread = $this->actions()->start($alice, $this->a->id, 'Subject', 'Mine', [$bob->id, $manager->id]);

        $this->expectException(AuthorizationException::class);
        $this->actions()->deleteMessage($manager, $thread->messages()->firstOrFail());
    }

    // -- Anonymisation -------------------------------------------------------------------------------------------

    public function test_anonymizing_a_user_erases_their_messages_and_keeps_the_conversation_readable(): void
    {
        [$alice, $bob] = $this->trio();
        $thread = $this->actions()->start($alice, $this->a->id, 'Project', 'Alice private details', [$bob->id]);
        $this->actions()->reply($bob, $thread, 'Bob keeps this');

        app(AnonymizeUser::class)->handle($alice);

        $erased = ThreadMessage::orderBy('id')->firstOrFail();
        $this->assertSame('', $erased->body);
        $this->assertNotNull($erased->erased_at);

        $this->actingAs($bob)->get('/messaging/threads/'.$thread->id)
            ->assertOk()->assertSee('Bob keeps this')->assertDontSee('Alice private details')
            ->assertSee(__('Deleted user'))->assertSee('This message was removed.');
    }

    // -- Widget --------------------------------------------------------------------------------------------------

    public function test_the_widget_shows_the_unread_count(): void
    {
        [$alice, $bob] = $this->trio();
        $this->actions()->start($alice, $this->a->id, 'Subject', 'Hello', [$bob->id]);

        Livewire::actingAs($bob)->test('messaging.unread-widget')->assertSee('1 unread conversations');
        Livewire::actingAs($alice)->test('messaging.unread-widget')->assertSee('No unread messages.');
    }

    public function test_a_user_without_store_or_conversation_has_no_messaging(): void
    {
        $this->enableMessaging();
        $loner = User::factory()->create();

        $this->actingAs($loner)->get('/messaging')->assertForbidden();
    }
}
