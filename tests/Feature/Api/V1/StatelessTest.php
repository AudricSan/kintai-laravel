<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class StatelessTest extends TestCase
{
    use RefreshDatabase;
    use UsesApi;

    public function test_no_cookie_and_no_session_whatever_the_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        foreach ([
            ['GET', '/api/v1/ping'],
            ['GET', '/api/v1/auth/me'],
            ['GET', '/api/v1/auth/tokens'],
            ['GET', '/api/v1/notifications'],
            ['GET', '/api/v1/missing'],
        ] as [$method, $uri]) {
            $response = $this->call($method, $uri, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            $this->assertSame([], $response->headers->getCookies(), $uri);
            $this->assertNull($response->headers->get('Set-Cookie'), $uri);
        }

        $this->assertFalse($this->app['session']->isStarted());
    }

    public function test_the_web_session_does_not_authenticate_the_api(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $login = $this->post(route('login.store'), ['login' => 'jane@example.com', 'password' => 'password']);
        $cookies = [];
        foreach ($login->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->assertNotSame([], $cookies);

        // Témoin : ces cookies ouvrent bien la session web...
        $this->call('GET', '/dashboard', [], $cookies)->assertSuccessful();
        // ... mais ne donnent aucun accès à l'API.
        $this->call('GET', '/api/v1/auth/me', [], $cookies, [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_api_routes_use_no_web_middleware(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertNotContains('web', $middleware, $route->uri());
            $this->assertStringStartsWith('api.v1.', (string) $route->getName(), $route->uri());
        }
    }

    public function test_a_csrf_token_is_not_needed(): void
    {
        $user = User::factory()->create();

        $this->post('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$this->tokenFor($user), 'Accept' => 'application/json'])
            ->assertNoContent();
    }
}
