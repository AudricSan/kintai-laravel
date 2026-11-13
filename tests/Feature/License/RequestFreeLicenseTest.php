<?php

namespace Tests\Feature\License;

use App\Actions\License\RequestFreeLicense;
use App\License\LicenseClient;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Demande d'une licence gratuite : POST /register, 202, 422, 429, serveur injoignable, audit et écran.
 */
class RequestFreeLicenseTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const BASE = 'https://lic.test/public/api/v1';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['kintai.license.api_url' => self::BASE, 'kintai.license.api_key' => 'product-key']);
        $this->seedRoles();
        $this->owner = $this->owner();
    }

    /** Remplace tout bouchon précédent : le premier qui correspond l'emporte dans Http::fake(). */
    private function fakeRegister(mixed $response): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([self::BASE.'/register' => $response]);
    }

    public function test_success_sends_the_protocol_and_audits_without_the_full_address(): void
    {
        $this->fakeRegister(Http::response(['status' => 'pending_verification'], 202));

        $result = $this->app->make(RequestFreeLicense::class)->handle($this->owner, ' Boss@Example.test ');

        $this->assertTrue($result->isAccepted());
        $this->assertSame('pending_verification', $result->status);
        Http::assertSent(fn ($request): bool => $request->url() === self::BASE.'/register'
            && $request->hasHeader('Authorization', 'Bearer product-key')
            && $request['email'] === 'boss@example.test'
            && is_string($request['instance_id']) && $request['instance_id'] !== ''
            && is_string($request['instance_label']));

        $logs = DB::table('activity_entries')->where('action', 'license.register')->get();
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('boss@example.test', (string) json_encode($logs));
    }

    public function test_server_errors_never_throw(): void
    {
        $client = $this->app->make(LicenseClient::class);

        $this->fakeRegister(Http::response(['error' => 'invalid_email'], 422));
        $r = $client->register('a@b.test', 'inst');
        $this->assertTrue($r->kind === 'rejected' && $r->error === 'invalid_email' && $r->httpStatus === 422 && ! $r->isRateLimited());

        $this->fakeRegister(Http::response([], 429));
        $this->assertTrue($client->register('a@b.test', 'inst')->isRateLimited());

        $this->fakeRegister(Http::response('oops', 500));
        $this->assertSame('unreachable', $client->register('a@b.test', 'inst')->kind);

        $this->fakeRegister(fn () => throw new ConnectionException('down'));
        $this->assertSame('unreachable', $client->register('a@b.test', 'inst')->kind);
    }

    public function test_not_configured_makes_no_request(): void
    {
        config(['kintai.license.api_key' => '']);

        $result = $this->app->make(LicenseClient::class)->register('a@b.test', 'inst');

        $this->assertSame('not_configured', $result->error);
        Http::assertNothingSent();
    }

    public function test_invalid_address_and_non_owner_are_refused_before_any_call(): void
    {
        try {
            $this->app->make(RequestFreeLicense::class)->handle($this->owner, 'not-an-email');
            $this->fail('An invalid address must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        try {
            $this->app->make(RequestFreeLicense::class)->handle(User::factory()->create(), 'a@b.test');
            $this->fail('A non-owner must be refused.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }

    public function test_the_screen_requests_a_free_license_and_reports_a_rate_limit(): void
    {
        $this->actingAs($this->owner);

        $this->fakeRegister(Http::response([], 429));
        Livewire::test('pages::admin.license')->set('freeEmail', 'a@b.test')->call('requestFree')
            ->assertSet('error', __('Too many requests. Try again later.'))->assertSet('freeEmail', 'a@b.test');

        $this->fakeRegister(Http::response(['status' => 'pending_verification'], 202));
        Livewire::test('pages::admin.license')->set('freeEmail', 'a@b.test')->call('requestFree')
            ->assertSet('error', '')->assertSet('freeEmail', '');
    }

    public function test_the_screen_explains_what_is_missing_without_a_product_key(): void
    {
        config(['kintai.license.api_key' => '']);
        $this->actingAs($this->owner);

        Livewire::test('pages::admin.license')->assertSeeHtml('data-test="license-free-unavailable"');
    }
}
