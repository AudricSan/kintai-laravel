<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\KintaiNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;
    use UsesApi;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => null]);
    }

    private function notify(User $user, int $reference = 5): DatabaseNotification
    {
        $known = $user->notifications()->pluck('id')->all();

        $user->notify(KintaiNotification::make(
            'shift_assigned',
            'A shift was added for you on :date from :start to :end (:store).',
            ['date' => '2026-11-02', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha'],
            '/my-shifts/day?date=2026-11-02',
            $reference,
        ));

        return $user->notifications()->whereNotIn('id', $known)->firstOrFail();
    }

    public function test_notifications_require_a_token(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);
    }

    public function test_list_is_paginated_newest_first_and_only_mine(): void
    {
        $other = User::factory()->create(['email' => null]);
        $this->notify($other);
        $old = $this->notify($this->user, 1);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $new = $this->notify($this->user, 2);

        $response = $this->actingWithToken($this->user)->getJson('/api/v1/notifications?per_page=1')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('unread_count', 2)
            ->assertJsonStructure(['data' => [['id', 'type', 'title', 'body', 'icon', 'link', 'reference_type', 'reference_id', 'read', 'read_at', 'created_at']], 'links', 'meta']);

        $this->getJson('/api/v1/notifications?per_page=1&page=2')->assertJsonPath('data.0.id', $old->id);
    }

    public function test_the_page_size_is_bounded(): void
    {
        $this->actingWithToken($this->user)->getJson('/api/v1/notifications?per_page=5000')
            ->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/notifications?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_the_unread_filter(): void
    {
        $read = $this->notify($this->user, 1);
        $read->markAsRead();
        $unread = $this->notify($this->user, 2);

        $response = $this->actingWithToken($this->user)->getJson('/api/v1/notifications?unread=1')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unread->id)->assertJsonPath('data.0.read', false);
        $this->getJson('/api/v1/notifications')->assertJsonCount(2, 'data');
    }

    public function test_show_returns_the_translated_text_in_the_language_of_the_request(): void
    {
        $notification = $this->notify($this->user);

        $english = $this->actingWithToken($this->user)->getJson('/api/v1/notifications/'.$notification->id)->assertOk();
        $english->assertJsonPath('data.title', 'New shift assigned')
            ->assertJsonPath('data.link', '/my-shifts/day?date=2026-11-02')
            ->assertJsonPath('data.reference_type', 'shift')
            ->assertJsonPath('data.reference_id', 5);
        $this->assertStringStartsWith('A shift was added for you on', $english->json('data.body'));

        $french = $this->getJson('/api/v1/notifications/'.$notification->id, ['Accept-Language' => 'fr'])->assertOk();
        $this->assertStringStartsWith('Un shift vous a été ajouté le', $french->json('data.body'));
        $this->assertNotSame('New shift assigned', $french->json('data.title'));
    }

    public function test_the_notification_of_another_user_is_not_found(): void
    {
        $theirs = $this->notify(User::factory()->create(['email' => null]));

        $this->actingWithToken($this->user);
        $this->getJson('/api/v1/notifications/'.$theirs->id)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/notifications/'.$theirs->id.'/read')->assertStatus(404);
        $this->deleteJson('/api/v1/notifications/'.$theirs->id)->assertStatus(404);

        $theirs->refresh();
        $this->assertNull($theirs->read_at);
        $this->assertNotNull(DatabaseNotification::find($theirs->id));
    }

    public function test_mark_as_read(): void
    {
        $notification = $this->notify($this->user);

        $this->actingWithToken($this->user)->postJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk()->assertJsonPath('data.read', true);

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_mark_all_as_read_touches_only_my_notifications(): void
    {
        $other = User::factory()->create(['email' => null]);
        $this->notify($this->user, 1);
        $this->notify($this->user, 2);
        $theirs = $this->notify($other);

        $this->actingWithToken($this->user)->postJson('/api/v1/notifications/read-all')->assertNoContent();

        $this->assertSame(0, $this->user->unreadNotifications()->count());
        $this->assertNull($theirs->refresh()->read_at);
    }

    public function test_delete_my_notification(): void
    {
        $notification = $this->notify($this->user);

        $this->actingWithToken($this->user)->deleteJson('/api/v1/notifications/'.$notification->id)->assertNoContent();

        $this->assertNull(DatabaseNotification::find($notification->id));
    }
}
