<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_is_public_and_describes_the_instance(): void
    {
        config(['app.name' => 'Kintai Demo', 'kintai.timezone' => 'Asia/Tokyo', 'kintai.version' => '1.2.3']);

        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJson([
                'name' => 'Kintai Demo',
                'version' => '1.2.3',
                'api_version' => '1',
                'locale' => 'en',
                'timezone' => 'Asia/Tokyo',
            ])
            ->assertJsonStructure(['features']);
    }

    public function test_ping_lists_core_features_and_registered_store_features(): void
    {
        config(['kintai.fcm.enabled' => false, 'kintai.webpush.public_key' => null, 'kintai.webpush.private_key' => null]);

        $features = $this->getJson('/api/v1/ping')->json('features');

        $this->assertContains('ical', $features);
        $this->assertContains('two_factor', $features);
        $this->assertContains('shifts', $features);
        $this->assertNotContains('push_fcm', $features);
        $this->assertNotContains('push_web', $features);
    }

    public function test_ping_announces_fcm_and_web_push_when_configured(): void
    {
        config([
            'kintai.fcm.enabled' => true,
            'kintai.fcm.project_id' => 'kintai-test',
            'kintai.webpush.public_key' => 'BPublicKeyForTests',
            'kintai.webpush.private_key' => 'PrivateKeyForTests',
        ]);

        $features = $this->getJson('/api/v1/ping')->json('features');

        $this->assertContains('push_fcm', $features);
        $this->assertContains('push_web', $features);
    }

    public function test_ping_exposes_nothing_sensitive(): void
    {
        config(['kintai.webpush.private_key' => 'PrivateKeyForTests', 'app.key' => 'base64:secret-for-tests']);

        $body = $this->getJson('/api/v1/ping')->getContent();

        $this->assertStringNotContainsString('PrivateKeyForTests', (string) $body);
        $this->assertStringNotContainsString('secret-for-tests', (string) $body);
        $this->assertSame(['name', 'version', 'api_version', 'features', 'locale', 'timezone'], array_keys(json_decode((string) $body, true)));
    }

    public function test_ping_sets_no_cookie_and_no_session(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
    }
}
