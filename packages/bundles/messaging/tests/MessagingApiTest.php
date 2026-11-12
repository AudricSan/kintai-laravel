<?php

namespace Kintai\Bundles\Messaging\Tests;

use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API de la messagerie : `/api/v1/messaging`, mêmes règles que les écrans (participants seulement, destinataires du
 * magasin), mêmes erreurs que l'API du Core.
 */
class MessagingApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
        $this->enableMessaging();
    }

    /**
     * Active le bundle comme le fait l'Owner : les droits par défaut des rôles manager et employee sont accordés.
     */
    private function enableMessaging(): void
    {
        $this->enableBundle('messaging');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('messaging') ?? throw new LogicException);
    }

    /**
     * @return array{User, User, User}
     */
    private function trio(): array
    {
        return [
            $this->employeeOf($this->a, ['name' => 'Alice']),
            $this->employeeOf($this->a, ['name' => 'Bob']),
            $this->employeeOf($this->a, ['name' => 'Carl']),
        ];
    }

    public function test_the_api_needs_a_token_and_the_routes_are_named(): void
    {
        $this->getJson('/api/v1/messaging/threads')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->assertSame(url('/api/v1/messaging/threads'), route('api.v1.messaging.threads.index'));
    }

    public function test_the_full_flow_start_read_reply_delete(): void
    {
        [$alice, $bob, $carl] = $this->trio();

        $id = $this->actingWithToken($alice)->postJson('/api/v1/messaging/threads', [
            'store_id' => $this->a->id, 'subject' => 'Inventory', 'body' => 'Count the shelves', 'recipient_ids' => [$bob->id],
            'id' => 999, 'creator_id' => $carl->id,
        ])->assertCreated()
            ->assertJsonPath('data.subject', 'Inventory')->assertJsonPath('data.creator_id', $alice->id)->assertJsonPath('data.unread', false)
            ->assertJsonCount(2, 'data.participants')
            ->json('data.id');
        $this->assertNotSame(999, $id);

        $this->actingWithToken($bob)->getJson('/api/v1/messaging/unread')->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingWithToken($bob)->getJson('/api/v1/messaging/threads')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.unread', true)->assertJsonPath('data.0.messages_count', 1);
        $this->actingWithToken($bob)->getJson('/api/v1/messaging/threads?tab=unread')->assertOk()->assertJsonCount(1, 'data');
        $this->actingWithToken($bob)->getJson('/api/v1/messaging/threads?tab=sent')->assertOk()->assertJsonCount(0, 'data');

        $this->actingWithToken($bob)->getJson("/api/v1/messaging/threads/{$id}/messages")->assertOk()
            ->assertJsonPath('data.0.body', 'Count the shelves')->assertJsonPath('data.0.mine', false)->assertJsonPath('data.0.sender.name', 'Alice');
        $this->actingWithToken($bob)->getJson('/api/v1/messaging/unread')->assertJsonPath('data.unread', 0);

        $replyId = $this->actingWithToken($bob)->postJson("/api/v1/messaging/threads/{$id}/messages", ['body' => 'Done', 'sender_id' => $alice->id, 'id' => 1])
            ->assertCreated()->assertJsonPath('data.mine', true)->assertJsonPath('data.sender.id', $bob->id)->json('data.id');
        $this->assertNotSame(1, $replyId);
        $this->actingWithToken($alice)->getJson('/api/v1/messaging/unread')->assertJsonPath('data.unread', 1);

        $this->actingWithToken($alice)->deleteJson("/api/v1/messaging/messages/{$replyId}")->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($bob)->deleteJson("/api/v1/messaging/messages/{$replyId}")->assertNoContent();
        $this->assertNull(ThreadMessage::find($replyId));

        $this->actingWithToken($bob)->deleteJson("/api/v1/messaging/threads/{$id}")->assertNoContent();
        $this->actingWithToken($bob)->getJson('/api/v1/messaging/threads')->assertJsonCount(0, 'data');
        $this->actingWithToken($alice)->getJson('/api/v1/messaging/threads')->assertJsonCount(1, 'data');
    }

    public function test_a_conversation_of_others_is_not_found_whatever_the_role(): void
    {
        [$alice, $bob, $carl] = $this->trio();
        $manager = $this->managerOf($this->a);
        $this->a->members()->attach($manager);
        $thread = MessageThread::create(['store_id' => $this->a->id, 'creator_id' => $alice->id, 'subject' => 'Secret', 'last_message_at' => now()]);
        ThreadMessage::create(['thread_id' => $thread->id, 'sender_id' => $alice->id, 'body' => 'Hidden text', 'sent_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $alice->id, 'is_read' => true]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $bob->id]);
        $message = ThreadMessage::firstOrFail();

        foreach ([$carl, $manager, $this->owner()] as $outsider) {
            $client = $this->actingWithToken($outsider);
            $client->getJson("/api/v1/messaging/threads/{$thread->id}")->assertNotFound()->assertJson(['code' => 'not_found']);
            $client->getJson("/api/v1/messaging/threads/{$thread->id}/messages")->assertNotFound();
            $client->getJson("/api/v1/messaging/threads/{$thread->id}/participants")->assertNotFound();
            $client->postJson("/api/v1/messaging/threads/{$thread->id}/messages", ['body' => 'Intrusion'])->assertNotFound();
            $client->postJson("/api/v1/messaging/threads/{$thread->id}/participants", ['user_id' => $outsider->id])->assertNotFound();
            $client->deleteJson("/api/v1/messaging/threads/{$thread->id}")->assertNotFound();
            $client->deleteJson("/api/v1/messaging/messages/{$message->id}")->assertNotFound();
            $client->getJson('/api/v1/messaging/threads')->assertOk()->assertJsonCount(0, 'data');
        }

        $this->assertSame(1, ThreadMessage::count());
        $this->assertSame(2, ThreadParticipant::count());
    }

    public function test_recipients_must_be_colleagues_of_the_store(): void
    {
        [$alice, $bob] = $this->trio();
        $stranger = $this->employeeOf($this->b);

        $payload = ['store_id' => $this->a->id, 'subject' => 'Hi', 'body' => 'Hello', 'recipient_ids' => [$stranger->id]];
        $this->actingWithToken($alice)->postJson('/api/v1/messaging/threads', $payload)->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['recipientIds']);
        $this->actingWithToken($alice)->postJson('/api/v1/messaging/threads', ['store_id' => $this->b->id] + $payload)->assertStatus(422);
        $this->actingWithToken($alice)->postJson('/api/v1/messaging/threads', ['recipient_ids' => []] + $payload)->assertStatus(422)->assertJsonValidationErrors(['recipient_ids']);

        $this->assertSame(0, MessageThread::count());

        $id = $this->actingWithToken($alice)->postJson('/api/v1/messaging/threads', ['recipient_ids' => [$bob->id]] + $payload)->assertCreated()->json('data.id');
        $this->actingWithToken($alice)->postJson("/api/v1/messaging/threads/{$id}/participants", ['user_id' => $stranger->id])->assertStatus(422);
    }

    public function test_a_participant_can_be_added_among_the_colleagues_of_the_store(): void
    {
        [$alice, $bob, $carl] = $this->trio();
        $id = $this->actingWithToken($alice)->postJson('/api/v1/messaging/threads', [
            'store_id' => $this->a->id, 'subject' => 'Hi', 'body' => 'Hello', 'recipient_ids' => [$bob->id],
        ])->json('data.id');

        $this->actingWithToken($carl)->getJson("/api/v1/messaging/threads/{$id}")->assertNotFound();
        $this->actingWithToken($alice)->postJson("/api/v1/messaging/threads/{$id}/participants", ['user_id' => $carl->id])->assertCreated()->assertJsonPath('data.user.name', 'Carl');
        $this->actingWithToken($alice)->postJson("/api/v1/messaging/threads/{$id}/participants", ['user_id' => $carl->id])->assertCreated();
        $this->assertSame(3, ThreadParticipant::count());
        $this->actingWithToken($carl)->getJson("/api/v1/messaging/threads/{$id}/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->actingWithToken($alice)->getJson("/api/v1/messaging/threads/{$id}/participants")->assertOk()->assertJsonCount(3, 'data');
    }
}
