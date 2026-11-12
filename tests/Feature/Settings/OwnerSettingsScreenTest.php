<?php

namespace Tests\Feature\Settings;

use App\Mail\MailTestMessage;
use App\Models\ActivityEntry;
use App\Models\AppSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Push\VapidKeys;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

class OwnerSettingsScreenTest extends TestCase
{
    use CreatesAccess;
    use FakesPush;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
    }

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    /** Contenu de la balise de thème du `<head>` de l'écran. */
    private function themeStyle(): string
    {
        preg_match('/<style data-kintai-theme>(.*?)<\/style>/s', (string) $this->get(route('settings.app'))->getContent(), $match);

        return $match[1] ?? '';
    }

    private function lastUpdate(): ActivityEntry
    {
        return ActivityEntry::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
    }

    public function test_only_the_owner_reaches_the_screen(): void
    {
        $this->get(route('settings.app'))->assertOk()->assertSee('Instance settings');

        $store = Store::factory()->create();
        $this->actingAs($this->managerOf($store));
        $this->get(route('settings.app'))->assertForbidden();
        Livewire::test('pages::admin.settings')->assertForbidden();

        $this->actingAs($this->employeeOf($store));
        $this->get(route('settings.app'))->assertForbidden();

        auth()->logout();
        $this->get(route('settings.app'))->assertRedirect(route('login'));
    }

    public function test_the_menu_entry_is_only_for_the_owner(): void
    {
        $this->get(route('settings.app'))->assertSee('data-test="nav-item-instance-settings"', false);

        $this->actingAs($this->managerOf(Store::factory()->create()));
        $this->get(route('dashboard'))->assertDontSee('nav-item-instance-settings', false);
    }

    public function test_a_non_owner_cannot_run_the_actions_of_a_screen_mounted_by_an_owner(): void
    {
        $screen = Livewire::test('pages::admin.settings')->set('subtitle', 'Hacked');

        $this->actingAs($this->managerOf(Store::factory()->create()));

        $screen->call('saveOrganization')->assertForbidden();
        $this->assertSame('', $this->settings()->subtitle());
    }

    public function test_the_organization_section_is_saved_and_normalized(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('subtitle', ' Acme Retail ')
            ->set('login_notice', 'Welcome back')
            ->set('support_email', 'help@example.com')
            ->set('public_url', 'HTTPS://Planning.Example.com/kintai/')
            ->call('saveOrganization')
            ->assertHasNoErrors();

        $settings = $this->settings();
        $this->assertSame('Acme Retail', $settings->subtitle());
        $this->assertSame('Welcome back', $settings->loginNotice());
        $this->assertSame('help@example.com', $settings->supportEmail());
        $this->assertSame('https://planning.example.com/kintai', $settings->publicUrl());
    }

    public function test_the_organization_texts_are_shown_on_the_login_page_and_the_sidebar(): void
    {
        $this->settings()->setMany([
            'organization.subtitle' => 'Acme Retail',
            'organization.login_notice' => 'Planned downtime on Sunday',
            'organization.support_email' => 'help@example.com',
        ]);

        auth()->logout();
        $this->get(route('login'))->assertSee('Acme Retail')->assertSee('Planned downtime on Sunday')->assertSee('help@example.com');

        $this->actingAs($this->owner)->get(route('settings.app'))->assertSee('data-test="app-subtitle"', false);
    }

    public function test_invalid_organization_values_are_refused_and_the_previous_ones_kept(): void
    {
        $this->settings()->setMany(['organization.subtitle' => 'Kept', 'organization.public_url' => 'https://kept.example.com']);

        $screen = Livewire::test('pages::admin.settings')
            ->set('subtitle', str_repeat('a', 101))
            ->set('login_notice', str_repeat('b', 301))
            ->set('support_email', 'not-an-email')
            ->set('public_url', 'ftp://example.com')
            ->call('saveOrganization');

        $screen->assertHasErrors(['subtitle', 'login_notice', 'support_email', 'public_url']);
        $this->assertSame('Kept', $this->settings()->subtitle());
        $this->assertSame('https://kept.example.com', $this->settings()->publicUrl());

        foreach (['https://user:pw@example.com', 'https://example.com/?a=1', 'https://example.com/#x', 'example.com', 'javascript:alert(1)'] as $url) {
            $screen->set('subtitle', 'ok')->set('login_notice', '')->set('support_email', '')->set('public_url', $url)->call('saveOrganization')->assertHasErrors(['public_url']);
        }
    }

    public function test_each_save_is_logged_with_the_changed_keys_and_never_the_values(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('support_email', 'secret-contact@example.com')
            ->set('subtitle', 'Acme')
            ->call('saveOrganization');

        $entry = $this->lastUpdate();
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame('organization', $entry->properties['section']);
        $this->assertEqualsCanonicalizing(['organization.subtitle', 'organization.support_email'], $entry->properties['keys']);
        $this->assertStringNotContainsString('secret-contact', (string) json_encode($entry->properties));

        Livewire::test('pages::admin.settings')->call('saveOrganization');
        $this->assertSame([], $this->lastUpdate()->properties['keys'] ?? []);
    }

    public function test_the_theme_colors_are_saved_and_injected_in_the_head_of_both_layouts(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('colors.primary', '#112233')
            ->set('colors.danger', '#AA0000')
            ->call('saveAppearance')
            ->assertHasNoErrors();

        $this->assertSame('#112233', $this->settings()->themeColor('primary'));
        $this->assertSame('#aa0000', $this->settings()->themeColor('danger'));

        // Layout de l'application.
        $this->get(route('settings.app'))
            ->assertSee('data-kintai-theme', false)
            ->assertSee('--kintai-primary:#112233', false)
            ->assertSee('--kintai-danger:#aa0000', false)
            ->assertSee('--color-accent:#112233', false);

        // Layout de connexion.
        auth()->logout();
        $this->get(route('login'))->assertSee('--kintai-primary:#112233', false);
    }

    public function test_the_default_theme_injects_nothing(): void
    {
        $this->get(route('settings.app'))->assertDontSee('data-kintai-theme', false);

        // Enregistrer les couleurs par défaut n'injecte rien non plus.
        Livewire::test('pages::admin.settings')->call('saveAppearance')->assertHasNoErrors();

        $this->get(route('settings.app'))->assertDontSee('data-kintai-theme', false);
    }

    public function test_dark_colors_apply_only_in_manual_mode(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('colors.primary', '#112233')
            ->set('dark_colors.primary', '#99aabb')
            ->set('dark_mode', 'manual')
            ->call('saveAppearance')
            ->assertHasNoErrors();

        $manual = $this->themeStyle();
        $this->assertStringContainsString('.dark{', $manual);
        $this->assertStringContainsString('--kintai-primary:#99aabb', $manual);

        Livewire::test('pages::admin.settings')->set('dark_mode', 'auto')->call('saveAppearance');

        $auto = $this->themeStyle();
        $this->assertStringNotContainsString('#99aabb', $auto);
        $this->assertStringContainsString('--kintai-primary:#112233', $auto);
    }

    public function test_invalid_theme_values_are_refused(): void
    {
        $this->settings()->set('theme.primary', '#112233');

        $screen = Livewire::test('pages::admin.settings')
            ->set('colors.primary', 'red; } body { display:none')
            ->set('dark_colors.info', '#12')
            ->set('dark_mode', 'rainbow')
            ->call('saveAppearance');

        $screen->assertHasErrors(['colors.primary', 'dark_colors.info', 'dark_mode']);
        $this->assertSame('#112233', $this->settings()->themeColor('primary'));
    }

    public function test_default_colors_can_be_restored(): void
    {
        $this->settings()->set('theme.primary', '#112233');

        Livewire::test('pages::admin.settings')
            ->call('resetColors')
            ->assertSet('colors.primary', '#ff9f4a')
            ->call('saveAppearance');

        $this->get(route('settings.app'))->assertDontSee('data-kintai-theme', false);
    }

    public function test_the_mail_sender_is_saved_and_replaces_the_mail_configuration(): void
    {
        $original = config('mail.from');

        Livewire::test('pages::admin.settings')
            ->set('from_address', 'planning@example.com')
            ->set('from_name', 'Acme Planning')
            ->call('saveMail')
            ->assertHasNoErrors();

        $this->assertSame('planning@example.com', config('mail.from.address'));
        $this->assertSame('Acme Planning', config('mail.from.name'));

        // Un vrai envoi (transport `array`) part avec cet expéditeur.
        Mail::to('someone@example.com')->send(new MailTestMessage('now', 'array'));
        $sent = $this->app->make('mail.manager')->mailer()->getSymfonyTransport()->messages()->last();
        $this->assertSame('planning@example.com', $sent->getEnvelope()->getSender()->getAddress());
        $this->assertSame('Acme Planning', $sent->getOriginalMessage()->getFrom()[0]->getName());

        // Vides, l'expéditeur de la configuration revient.
        Livewire::test('pages::admin.settings')->set('from_address', '')->set('from_name', '')->call('saveMail');
        $this->assertSame($original, config('mail.from'));
    }

    public function test_an_invalid_sender_is_refused_without_overwriting_the_previous_one(): void
    {
        $this->settings()->set('mail.from_address', 'planning@example.com');

        Livewire::test('pages::admin.settings')
            ->set('from_address', 'not-an-address')
            ->set('from_name', str_repeat('n', 101))
            ->call('saveMail')
            ->assertHasErrors(['from_address', 'from_name']);

        $this->assertSame('planning@example.com', $this->settings()->mailFromAddress());
    }

    public function test_the_test_mail_is_sent_and_the_result_displayed(): void
    {
        Mail::fake();

        Livewire::test('pages::admin.settings')
            ->set('test_to', 'dest@example.com')
            ->call('sendTestMail')
            ->assertHasNoErrors()
            ->assertSet('test_result.success', true)
            ->assertSeeHtml('data-test="mail-test-success"');

        Mail::assertSent(MailTestMessage::class, fn (MailTestMessage $mail): bool => $mail->hasTo('dest@example.com'));
        $this->assertSame(1, ActivityEntry::query()->where('action', 'settings.mail_tested')->count());
    }

    public function test_the_test_mail_reports_a_readable_error(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Connection could not be established with host "smtp.example.com:587"'));

        Livewire::test('pages::admin.settings')
            ->set('test_to', 'dest@example.com')
            ->call('sendTestMail')
            ->assertSet('test_result.success', false)
            ->assertSet('test_result.error', 'Connection could not be established with host "smtp.example.com:587"')
            ->assertSeeHtml('data-test="mail-test-error"');
    }

    public function test_the_test_mail_refuses_an_invalid_address(): void
    {
        Mail::fake();

        Livewire::test('pages::admin.settings')->set('test_to', 'nope')->call('sendTestMail')->assertHasErrors(['test_to']);
        Livewire::test('pages::admin.settings')->set('test_to', '')->call('sendTestMail')->assertHasErrors(['test_to']);

        Mail::assertNothingSent();
    }

    public function test_the_instance_language_and_time_zone_are_saved_and_applied(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('locale', 'fr')
            ->set('timezone', 'Asia/Tokyo')
            ->call('saveInstance')
            ->assertHasNoErrors();

        $this->assertSame('fr', $this->settings()->instanceLocale());
        $this->assertSame('Asia/Tokyo', $this->settings()->instanceTimezone());
        $this->assertSame('Asia/Tokyo', config('kintai.timezone'));
        $this->assertSame('fr', config('app.locale'));
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_an_unknown_language_or_time_zone_is_refused(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('locale', 'de')
            ->set('timezone', 'Mars/Olympus')
            ->call('saveInstance')
            ->assertHasErrors(['locale', 'timezone']);

        $this->assertFalse($this->settings()->isStored('instance.locale'));
        $this->assertFalse($this->settings()->isStored('instance.timezone'));
    }

    public function test_the_maintenance_section_is_saved_and_validated(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('maintenance_enabled', true)
            ->set('maintenance_message', 'Back at 6pm')
            ->call('saveMaintenance')
            ->assertHasNoErrors();

        $this->assertTrue($this->settings()->maintenanceEnabled());
        $this->assertSame('Back at 6pm', $this->settings()->maintenanceMessage());

        Livewire::test('pages::admin.settings')
            ->set('maintenance_message', str_repeat('m', 501))
            ->call('saveMaintenance')
            ->assertHasErrors(['maintenance_message']);

        $this->assertSame('Back at 6pm', $this->settings()->maintenanceMessage());
    }

    public function test_the_limits_are_saved_within_their_bounds(): void
    {
        Livewire::test('pages::admin.settings')
            ->set('api_rate_limit', '0')
            ->set('activity_retention_days', '3650')
            ->set('notifications_retention_days', '7')
            ->call('saveLimits')
            ->assertHasNoErrors();

        $this->assertSame(0, $this->settings()->apiRateLimit());
        $this->assertSame(3650, $this->settings()->activityRetentionDays());
        $this->assertSame(7, $this->settings()->notificationsRetentionDays());

        $screen = Livewire::test('pages::admin.settings')
            ->set('api_rate_limit', '100001')
            ->set('activity_retention_days', '3651')
            ->set('notifications_retention_days', '6')
            ->call('saveLimits');

        $screen->assertHasErrors(['api_rate_limit', 'activity_retention_days', 'notifications_retention_days']);

        $screen->set('api_rate_limit', '-1')->set('activity_retention_days', 'abc')->set('notifications_retention_days', '10.5')->call('saveLimits')
            ->assertHasErrors(['api_rate_limit', 'activity_retention_days', 'notifications_retention_days']);

        $this->assertSame(0, $this->settings()->apiRateLimit());
    }

    public function test_the_channel_states_are_shown(): void
    {
        config(['mail.default' => 'smtp', 'kintai.fcm.enabled' => true, 'kintai.fcm.project_id' => 'demo', 'kintai.webpush.public_key' => null, 'kintai.webpush.private_key' => null]);

        $html = (string) Livewire::test('pages::admin.settings')->html();

        $this->assertMatchesRegularExpression('/data-test="channel-mail".*?Ready/s', $html);
        $this->assertMatchesRegularExpression('/data-test="channel-webpush".*?Not configured/s', $html);
        $this->assertMatchesRegularExpression('/data-test="channel-fcm".*?Enabled/s', $html);
    }

    public function test_regenerating_the_vapid_keys_replaces_them_and_drops_the_subscriptions(): void
    {
        $this->configureVapid();
        $this->subscribe($this->owner);
        $this->subscribe(User::factory()->create(), 'https://fcm.googleapis.com/fcm/send/other');
        $this->assertSame(2, WebPushSubscription::query()->count());

        Livewire::test('pages::admin.settings')
            ->call('confirmRegenerateVapid')
            ->call('regenerateVapid')
            ->assertHasNoErrors();

        $this->assertSame(0, WebPushSubscription::query()->count());

        $keys = $this->app->make(VapidKeys::class);
        $this->assertNotSame('BPublicKeyForTests', $keys->publicKey());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{87}$/', (string) $keys->publicKey());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $keys->privateKey());
        $this->assertTrue($keys->isConfigured());

        // La clé privée est chiffrée en base ; la clé publique est lisible.
        $storedPrivate = AppSetting::query()->findOrFail('webpush.private_key')->value;
        $this->assertNotSame($keys->privateKey(), $storedPrivate);
        $this->assertSame($keys->privateKey(), Crypt::decryptString($storedPrivate));

        // Journalisé, sans aucune clé.
        $entry = ActivityEntry::query()->where('action', 'settings.vapid_regenerated')->firstOrFail();
        $this->assertSame(2, $entry->properties['subscriptions_deleted']);
        $this->assertStringNotContainsString((string) $keys->privateKey(), (string) json_encode($entry->properties));
        $this->assertStringNotContainsString((string) $keys->publicKey(), (string) json_encode($entry->properties));
    }

    public function test_the_vapid_confirmation_modal_explains_the_consequence(): void
    {
        Livewire::test('pages::admin.settings')
            ->assertSee('The existing browser subscriptions become invalid')
            ->assertSeeHtml('data-test="confirm-regenerate-vapid"');
    }

    public function test_a_non_owner_cannot_regenerate_the_keys(): void
    {
        $screen = Livewire::test('pages::admin.settings');
        $this->actingAs($this->managerOf(Store::factory()->create()));

        $screen->call('regenerateVapid')->assertForbidden();
        $this->assertFalse($this->settings()->isStored('webpush.public_key'));
    }

    public function test_the_owner_can_send_himself_a_test_notification(): void
    {
        Livewire::test('pages::admin.settings')->call('sendTestNotification')->assertHasNoErrors();

        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $this->owner->id)->count());
    }
}
