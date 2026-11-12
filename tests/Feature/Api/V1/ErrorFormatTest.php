<?php

namespace Tests\Feature\Api\V1;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class ErrorFormatTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    /**
     * @param  array<string, mixed>  $json
     */
    private function assertErrorShape(array $json, string $code): void
    {
        $this->assertSame($code, $json['code']);
        $this->assertIsString($json['error']);
        $this->assertNotSame('', $json['error']);
    }

    public function test_401_is_json_even_without_accept_header(): void
    {
        $response = $this->get('/api/v1/auth/me');

        $response->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertErrorShape($response->json(), 'unauthenticated');
        $this->assertSame('Authentication is required.', $response->json('error'));
        $this->assertSame(['error', 'code'], array_keys($response->json()));
    }

    public function test_403_when_the_right_is_missing(): void
    {
        $store = Store::factory()->create();
        $this->seedRoles();
        $employee = $this->employeeOf($store);
        $colleague = $this->employeeOf($store);

        $response = $this->actingWithToken($employee)->get('/api/v1/users/'.$colleague->id.'/nav-prefs');

        $response->assertStatus(403);
        $this->assertErrorShape($response->json(), 'forbidden');
    }

    public function test_404_for_an_unknown_route_and_an_unknown_resource(): void
    {
        $unknownRoute = $this->get('/api/v1/does-not-exist');
        $unknownRoute->assertStatus(404);
        $this->assertErrorShape($unknownRoute->json(), 'not_found');

        $unknownNotification = $this->actingWithToken(User::factory()->create())->get('/api/v1/notifications/'.fake()->uuid());
        $unknownNotification->assertStatus(404);
        $this->assertErrorShape($unknownNotification->json(), 'not_found');
    }

    public function test_405_for_a_wrong_method(): void
    {
        $response = $this->post('/api/v1/ping');

        $response->assertStatus(405);
        $this->assertErrorShape($response->json(), 'method_not_allowed');
        $this->assertNotNull($response->headers->get('Allow'));
    }

    public function test_422_carries_the_errors_by_field(): void
    {
        $response = $this->post('/api/v1/auth/login', []);

        $response->assertStatus(422);
        $this->assertErrorShape($response->json(), 'validation_failed');
        $this->assertArrayHasKey('login', $response->json('errors'));
        $this->assertIsArray($response->json('errors.login'));
    }

    public function test_429_carries_retry_after(): void
    {
        config(['kintai.api.rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->get('/api/v1/ping')->assertOk();
        }

        $response = $this->get('/api/v1/ping');

        $response->assertStatus(429);
        $this->assertErrorShape($response->json(), 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_the_limit_is_per_token_and_per_ip_without_token(): void
    {
        config(['kintai.api.rate_limit' => 2]);
        $first = $this->tokenFor(User::factory()->create());
        $second = $this->tokenFor(User::factory()->create());

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$first])->assertOk();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$first])->assertOk();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$first])->assertStatus(429);

        // L'autre jeton a son propre compteur, de même que les requêtes sans jeton (par IP).
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$second])->assertOk();
        $this->getJson('/api/v1/ping')->assertOk();
    }

    public function test_the_default_limit_is_300_per_minute(): void
    {
        $this->assertSame(300, config('kintai.api.rate_limit'));
    }

    public function test_500_hides_the_detail_outside_debug(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret detail'));

        $response = $this->get('/api/v1/boom');

        $response->assertStatus(500);
        $this->assertErrorShape($response->json(), 'server_error');
        $this->assertStringNotContainsString('secret detail', (string) $response->getContent());
        $this->assertSame(['error', 'code'], array_keys($response->json()));
    }

    public function test_500_gives_the_detail_in_debug(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret detail'));

        $response = $this->get('/api/v1/boom');

        $response->assertStatus(500);
        $this->assertSame('server_error', $response->json('code'));
        $this->assertSame('secret detail', $response->json('detail'));
    }

    public function test_messages_follow_accept_language(): void
    {
        $this->get('/api/v1/auth/me', ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'Une authentification est requise.');

        $this->get('/api/v1/nothing', ['Accept-Language' => 'fr'])
            ->assertStatus(404)
            ->assertJsonPath('error', 'Ressource introuvable.');

        $this->post('/api/v1/auth/login', [], ['Accept-Language' => 'fr'])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Le champ identifiant est obligatoire.');
    }

    public function test_an_unsupported_language_falls_back_to_the_instance_language(): void
    {
        $this->get('/api/v1/auth/me', ['Accept-Language' => 'de-DE,de;q=0.9'])
            ->assertJsonPath('error', 'Authentication is required.');
    }

    public function test_the_user_language_applies_without_accept_language_and_the_header_wins(): void
    {
        $french = User::factory()->create(['locale' => 'fr']);
        $token = $this->tokenFor($french);

        // Le client de test ajoute un « Accept-Language: en-us » par défaut : un client sans en-tête l'envoie vide.
        $this->get('/api/v1/notifications/'.fake()->uuid(), ['Authorization' => 'Bearer '.$token, 'Accept-Language' => ''])
            ->assertStatus(404)
            ->assertJsonPath('error', 'Ressource introuvable.');

        $this->get('/api/v1/notifications/'.fake()->uuid(), ['Authorization' => 'Bearer '.$token, 'Accept-Language' => 'en'])
            ->assertStatus(404)
            ->assertJsonPath('error', 'Resource not found.');
    }

    public function test_pages_of_the_web_application_keep_their_own_error_pages(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('"code"', (string) $response->getContent());
    }
}
