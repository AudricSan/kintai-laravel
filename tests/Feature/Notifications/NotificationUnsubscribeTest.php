<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NotificationUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'emma@example.test']);
    }

    private function link(string $type = 'shift_assigned', ?User $user = null): string
    {
        return URL::signedRoute('notifications.unsubscribe', ['user' => ($user ?? $this->user)->id, 'type' => $type]);
    }

    public function test_the_signed_link_shows_a_confirmation_page_without_login(): void
    {
        $this->get($this->link())->assertOk()->assertSee('Unsubscribe me')->assertSee('New shift assigned');

        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_the_signed_link_does_not_expire(): void
    {
        $this->travel(5)->years();

        $this->get($this->link())->assertOk();
    }

    public function test_confirming_disables_only_the_mail_channel_of_that_type(): void
    {
        $preferences = app(NotificationPreferences::class);

        $this->post($this->link())->assertOk()->assertSee('Unsubscribed');

        $this->assertFalse($preferences->isEnabled($this->user, 'shift_assigned', 'mail'));
        $this->assertTrue($preferences->isEnabled($this->user, 'shift_assigned', 'push'));
        $this->assertTrue($preferences->isEnabled($this->user, 'shift_deleted', 'mail'));
        $this->assertSame(1, NotificationPreference::count());
    }

    public function test_the_one_click_post_works_without_a_session(): void
    {
        $this->call('POST', $this->link(), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertFalse(app(NotificationPreferences::class)->isEnabled($this->user, 'shift_assigned', 'mail'));
    }

    public function test_an_invalid_or_altered_signature_is_refused(): void
    {
        $this->get(route('notifications.unsubscribe', ['user' => $this->user->id, 'type' => 'shift_assigned']))->assertForbidden();
        $this->post(route('notifications.unsubscribe', ['user' => $this->user->id, 'type' => 'shift_assigned', 'signature' => 'abc']))->assertForbidden();

        $other = User::factory()->create();
        $altered = str_replace('user='.$this->user->id, 'user='.$other->id, $this->link());
        $this->get($altered)->assertForbidden()->assertSee('not valid');
        $this->post($altered)->assertForbidden();

        $swapped = str_replace('type=shift_assigned', 'type=shift_deleted', $this->link());
        $this->post($swapped)->assertForbidden();

        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_unknown_and_internal_types_are_refused_even_when_signed(): void
    {
        $this->get($this->link('nope'))->assertForbidden();
        $this->post($this->link('notification_test'))->assertForbidden();
    }

    public function test_the_page_is_written_in_the_user_language(): void
    {
        $this->user->forceFill(['locale' => 'fr'])->save();

        $this->get($this->link())->assertOk()->assertSee('Me désabonner')->assertSee('Nouveau shift assigné');
    }

    public function test_the_mail_carries_the_unsubscribe_link_and_headers(): void
    {
        $this->user->notify(KintaiNotification::make('shift_assigned', 'A shift was added for you on :date from :start to :end (:store).', ['date' => '2026-11-02', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha']));

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->firstOrFail()->getOriginalMessage();
        $headers = $message->getHeaders();

        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());

        $header = $headers->get('List-Unsubscribe')->getBodyAsString();
        $this->assertMatchesRegularExpression('#^<http.*notifications/unsubscribe\?.*signature=[0-9a-f]+>$#', $header);

        $link = trim($header, '<>');
        $this->assertTrue(str_contains($message->toString(), 'notifications/unsubscribe'));

        // Le lien de l'en-tête est utilisable tel quel.
        $this->post($link)->assertOk();
        $this->assertFalse(app(NotificationPreferences::class)->isEnabled($this->user, 'shift_assigned', 'mail'));
    }

    public function test_the_test_notification_mail_has_no_unsubscribe_header(): void
    {
        $this->user->notify(KintaiNotification::make('notification_test', 'This is a test notification sent from your notification settings. If you can read it, this channel works.'));

        $headers = Mail::mailer('array')->getSymfonyTransport()->messages()->firstOrFail()->getOriginalMessage()->getHeaders();

        $this->assertFalse($headers->has('List-Unsubscribe'));
    }
}
