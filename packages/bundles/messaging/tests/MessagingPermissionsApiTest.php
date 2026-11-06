<?php

namespace Kintai\Bundles\Messaging\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

// Le fond commun n'est pas une classe de test : le chargeur de PHPUnit ne le trouve pas seul.
require_once __DIR__.'/PermissionFixtures.php';

/**
 * API de la messagerie et permissions : 403 sans `messaging.view/send/create`, 404 pour la conversation d'autrui même
 * avec toutes les permissions.
 */
class MessagingPermissionsApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use PermissionFixtures;
    use RefreshDatabase;
    use UsesApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMessaging();
    }

    public function test_every_endpoint_refuses_a_user_without_permission(): void
    {
        $none = $this->userWith([]);
        $reader = $this->userWith(['messaging.view']);
        $thread = $this->threadTo($reader);
        $message = ThreadMessage::firstOrFail();
        $id = $thread->id;
        // Même participant de la conversation, sans permission : aucune lecture, aucun retrait.
        ThreadParticipant::create(['thread_id' => $id, 'user_id' => $none->id, 'is_read' => false]);

        $this->actingWithToken($none)->getJson('/api/v1/messaging/threads')->assertForbidden();
        $this->actingWithToken($none)->getJson('/api/v1/messaging/unread')->assertForbidden();
        $this->actingWithToken($none)->postJson('/api/v1/messaging/threads', ['store_id' => $this->a->id, 'subject' => 'S', 'body' => 'B', 'recipient_ids' => [$reader->id]])->assertForbidden();
        $this->actingWithToken($none)->getJson("/api/v1/messaging/threads/{$id}")->assertForbidden();
        $this->actingWithToken($none)->getJson("/api/v1/messaging/threads/{$id}/messages")->assertForbidden();
        $this->actingWithToken($none)->getJson("/api/v1/messaging/threads/{$id}/participants")->assertForbidden();
        $this->actingWithToken($none)->postJson("/api/v1/messaging/threads/{$id}/messages", ['body' => 'x'])->assertForbidden();
        $this->actingWithToken($none)->deleteJson("/api/v1/messaging/threads/{$id}")->assertForbidden();
        $this->actingWithToken($none)->deleteJson("/api/v1/messaging/messages/{$message->id}")->assertForbidden();

        $participation = ThreadParticipant::where('thread_id', $id)->where('user_id', $none->id)->firstOrFail();
        $this->assertFalse($participation->is_read);
        $this->assertNull($participation->hidden_at);
        $this->assertSame(1, MessageThread::count());
    }

    public function test_the_view_permission_alone_reads_but_neither_creates_nor_sends(): void
    {
        $reader = $this->userWith(['messaging.view']);
        $colleague = $this->employeeOf($this->a);
        $thread = $this->threadTo($reader);

        $this->actingWithToken($reader)->getJson('/api/v1/messaging/threads')->assertOk()->assertJsonCount(1, 'data');
        $this->actingWithToken($reader)->getJson('/api/v1/messaging/unread')->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingWithToken($reader)->getJson("/api/v1/messaging/threads/{$thread->id}/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->actingWithToken($reader)->postJson('/api/v1/messaging/threads', ['store_id' => $this->a->id, 'subject' => 'S', 'body' => 'B', 'recipient_ids' => [$colleague->id]])->assertForbidden();
        $this->actingWithToken($reader)->postJson("/api/v1/messaging/threads/{$thread->id}/messages", ['body' => 'Sneaky'])->assertForbidden();
        $this->actingWithToken($reader)->postJson("/api/v1/messaging/threads/{$thread->id}/participants", ['user_id' => $colleague->id])->assertForbidden();

        $this->assertSame(1, MessageThread::count());
        $this->assertSame(1, ThreadMessage::count());
        $this->assertSame(2, ThreadParticipant::count());
    }

    public function test_the_send_permission_replies_and_adds_a_participant(): void
    {
        $writer = $this->userWith(['messaging.view', 'messaging.send']);
        $colleague = $this->employeeOf($this->a);
        $thread = $this->threadTo($writer);

        $this->actingWithToken($writer)->postJson("/api/v1/messaging/threads/{$thread->id}/messages", ['body' => 'Thanks'])->assertCreated();
        $this->actingWithToken($writer)->postJson("/api/v1/messaging/threads/{$thread->id}/participants", ['user_id' => $colleague->id])->assertCreated();
        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_the_create_permission_alone_starts_a_conversation_but_reads_nothing(): void
    {
        $creator = $this->userWith(['messaging.create']);
        $colleague = $this->employeeOf($this->a);

        $this->actingWithToken($creator)->postJson('/api/v1/messaging/threads', ['store_id' => $this->a->id, 'subject' => 'S', 'body' => 'B', 'recipient_ids' => [$colleague->id]])
            ->assertCreated();
        $this->actingWithToken($creator)->getJson('/api/v1/messaging/threads')->assertForbidden();
    }

    public function test_all_permissions_never_give_access_to_the_conversation_of_others(): void
    {
        $alice = $this->employeeOf($this->a, ['name' => 'Alice']);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob']);
        $thread = $this->actions()->start($alice, $this->a->id, 'Private', 'Secret', [$bob->id]);
        $message = ThreadMessage::firstOrFail();

        foreach ([$this->owner(), $this->managerOf($this->a), $this->userWith($this->allPermissions(), $this->a, true)] as $user) {
            $this->actingWithToken($user)->getJson("/api/v1/messaging/threads/{$thread->id}")->assertNotFound();
            $this->actingWithToken($user)->getJson("/api/v1/messaging/threads/{$thread->id}/messages")->assertNotFound();
            $this->actingWithToken($user)->postJson("/api/v1/messaging/threads/{$thread->id}/messages", ['body' => 'Intrusion'])->assertNotFound();
            $this->actingWithToken($user)->deleteJson("/api/v1/messaging/threads/{$thread->id}")->assertNotFound();
            $this->actingWithToken($user)->deleteJson("/api/v1/messaging/messages/{$message->id}")->assertNotFound();
            $this->actingWithToken($user)->getJson('/api/v1/messaging/threads')->assertOk()->assertJsonCount(0, 'data');
            $this->assertSame(0, app(MessagingAccess::class)->unreadCount($user));
        }

        $this->assertSame(1, ThreadMessage::count());
    }

    public function test_the_view_permission_of_one_store_does_not_open_the_conversations_of_another(): void
    {
        $user = $this->userWith(['messaging.view', 'messaging.send'], $this->a);
        $this->b->members()->attach($user);
        $thread = $this->threadTo($user);
        // L'utilisateur participe aussi à une conversation du magasin Beta, où il n'a aucun droit.
        $this->actions()->start($this->employeeOf($this->b), $this->b->id, 'Beta', 'Hi', [$user->id]);
        $foreign = MessageThread::where('store_id', $this->b->id)->firstOrFail();

        $this->actingWithToken($user)->getJson('/api/v1/messaging/threads')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $thread->id);
        $this->actingWithToken($user)->getJson('/api/v1/messaging/unread')->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingWithToken($user)->getJson("/api/v1/messaging/threads/{$foreign->id}")->assertForbidden();
        $this->actingWithToken($user)->postJson("/api/v1/messaging/threads/{$foreign->id}/messages", ['body' => 'No'])->assertForbidden();
    }
}
