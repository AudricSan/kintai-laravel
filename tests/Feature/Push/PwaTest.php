<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_worker_is_served_publicly_as_javascript(): void
    {
        $response = $this->get(route('pwa.sw'));

        $response->assertOk();
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('Service-Worker-Allowed', '/');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $content = (string) $response->getContent();
        foreach (["addEventListener('push'", "addEventListener('notificationclick'", "addEventListener('pushsubscriptionchange'", 'showNotification'] as $needle) {
            $this->assertStringContainsString($needle, $content);
        }
    }

    public function test_the_service_worker_is_silent_during_quiet_hours(): void
    {
        $this->assertStringContainsString("silent: data.quiet === '1'", (string) $this->get(route('pwa.sw'))->getContent());
    }

    public function test_a_notification_click_only_opens_a_same_origin_url(): void
    {
        // Pas de moteur JS dans la suite : on contrôle le code de décision de l'adresse à ouvrir.
        $content = (string) $this->get(route('pwa.sw'))->getContent();

        $this->assertStringContainsString('function targetFor(data)', $content);
        $this->assertStringContainsString('url.origin === self.location.origin', $content);
        $this->assertStringContainsString("data.link.charAt(0) === '/'", $content);
        // Le lien brut n'est jamais passé tel quel à openWindow / navigate : seule la valeur de targetFor() l'est.
        $this->assertStringContainsString('const target = targetFor(event.notification.data || {});', $content);
        $this->assertStringContainsString('openWindow(target)', $content);
        $this->assertStringNotContainsString('openWindow(data', $content);
    }

    public function test_the_manifest_is_served_with_the_manifest_mime_type(): void
    {
        $response = $this->get(route('pwa.manifest'));

        $response->assertOk();
        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $manifest = $response->json();
        $this->assertSame(config('app.name'), $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['theme_color']);
        $this->assertNotEmpty($manifest['background_color']);
        $this->assertNotEmpty($manifest['start_url']);
        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(parse_url($icon['src'], PHP_URL_PATH)));
        }
    }

    public function test_pages_link_the_manifest_and_signed_in_pages_carry_the_push_config(): void
    {
        config(['kintai.webpush.public_key' => 'BPublicKeyForTests', 'kintai.webpush.private_key' => 'x']);

        $this->get(route('login'))->assertOk()->assertSee('rel="manifest"', false)->assertDontSee('kintai-push', false);

        $this->actingAs(User::factory()->create())->get(route('notification-preferences.edit'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('name="kintai-push"', false)
            ->assertSee('data-vapid-key="BPublicKeyForTests"', false)
            ->assertSee('name="csrf-token"', false)
            ->assertSee('data-push-panel', false)
            ->assertSee('data-push-enable', false)
            ->assertSee('data-push-logout', false)
            ->assertSee('name="push_endpoint"', false);
    }
}
