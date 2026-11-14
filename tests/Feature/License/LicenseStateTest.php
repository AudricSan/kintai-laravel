<?php

namespace Tests\Feature\License;

use App\Actions\License\ActivateLicense;
use App\Actions\License\DeactivateLicense;
use App\Actions\License\ValidateLicense;
use App\License\LicenseResult;
use App\License\LicenseState;
use App\License\LicenseStatus;
use App\Models\User;
use App\Settings\AppSettings;
use App\System\CoreSystemAlerts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\SignsLicenseTokens;
use Tests\TestCase;

class LicenseStateTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use SignsLicenseTokens;

    private const BASE = 'https://lic.test/public/api/v1';

    private const KEY = 'KEY-SECRET-1234-ABCD';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        config(['kintai.license.api_url' => self::BASE, 'kintai.license.api_key' => 'product-key', 'kintai.license.issuer' => null]);
        $this->useTestKey();
        $this->seedRoles();
        $this->owner = $this->owner();
    }

    private function state(): LicenseState
    {
        return $this->app->make(LicenseState::class);
    }

    /** Remplace tout bouchon précédent : le premier qui correspond l'emporte dans Http::fake(). */
    private function fakeRaw(string $endpoint, mixed $response): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([self::BASE.'/'.$endpoint => $response]);
    }

    /** @param  array<string, mixed>  $body */
    private function fakeAnswer(string $endpoint, array $body, int $status = 200): void
    {
        $this->fakeRaw($endpoint, Http::response($body, $status));
    }

    /** @return array<string, mixed> */
    private function okBody(?string $token = null): array
    {
        return ['valid' => true, 'status' => 'active', 'type' => 'subscription', 'issued_at' => now()->toIso8601String(), 'expires_at' => now()->addYear()->toIso8601String(), 'max_activations' => 2, 'active_activations' => 1, 'license_token' => $token ?? $this->token()];
    }

    private function activate(): LicenseResult
    {
        $this->fakeAnswer('activate', $this->okBody());

        return $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY);
    }

    public function test_activation_stores_the_license_and_sends_the_protocol(): void
    {
        $result = $this->activate();

        $this->assertTrue($result->isValid());
        $status = $this->state()->status();
        $this->assertSame(LicenseStatus::ACTIVE, $status->state);
        $this->assertSame('pro', $status->tier());
        $this->assertSame(5, $this->state()->limit('max_stores'));
        $this->assertNull($this->state()->limit('max_something_else'));
        $this->assertNotNull($status->validatedAt);
        $this->assertSame(self::KEY, $this->state()->licenseKey());

        Http::assertSent(fn ($request): bool => $request->url() === self::BASE.'/activate'
            && $request->header('Authorization') === ['Bearer product-key']
            && $request['license_key'] === self::KEY
            && $request['instance_id'] === $this->state()->instanceId()
            && isset($request['instance_label']));

        $this->assertDatabaseMissing('app_settings', ['key' => 'license.key', 'value' => self::KEY]);
    }

    public function test_the_instance_id_is_a_stable_uuid(): void
    {
        $id = $this->state()->instanceId();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertSame($id, $this->app->make(LicenseState::class)->instanceId());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusals(): array
    {
        return [
            'license_not_found' => ['license_not_found', LicenseStatus::REFUSED],
            'license_expired' => ['license_expired', LicenseStatus::EXPIRED],
            'license_suspended' => ['license_suspended', LicenseStatus::SUSPENDED],
            'license_revoked' => ['license_revoked', LicenseStatus::REVOKED],
            'max_activations_reached' => ['max_activations_reached', LicenseStatus::REFUSED],
            'not_activated' => ['not_activated', LicenseStatus::REFUSED],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_activation_keeps_the_free_tier(string $error, string $unused): void
    {
        $this->fakeAnswer('activate', ['valid' => false, 'error' => $error, 'max_activations' => 1]);

        $result = $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY);

        $this->assertTrue($result->isRefused());
        $this->assertSame($error, $result->error);
        $this->assertSame(LicenseStatus::FREE, $this->state()->state());
        $this->assertFalse($this->state()->hasKey());
        $this->assertDatabaseHas('activity_entries', ['action' => 'license.refused']);
    }

    #[DataProvider('refusals')]
    public function test_an_explicit_refusal_removes_the_rights_at_once(string $error, string $expected): void
    {
        $this->activate();
        $this->assertTrue($this->state()->status()->grantsRights());

        $this->fakeAnswer('validate', ['valid' => false, 'error' => $error]);
        $this->app->make(ValidateLicense::class)->handle($this->owner);

        $status = $this->state()->status();
        $this->assertSame($expected, $status->state);
        $this->assertFalse($status->grantsRights());
        $this->assertTrue($status->isProblem());
        $this->assertNull($this->state()->limit('max_stores'));
    }

    public function test_a_token_with_a_bad_signature_grants_nothing(): void
    {
        [$otherPrivate] = $this->makeKeyPair();
        $this->fakeAnswer('activate', $this->okBody($this->token([], $otherPrivate)));

        $result = $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY);

        $this->assertSame('invalid_token', $result->error);
        $this->assertSame(LicenseStatus::FREE, $this->state()->state());
        $this->assertFalse($this->state()->hasKey());
    }

    public function test_a_stored_token_is_verified_on_every_read(): void
    {
        $this->activate();
        [$otherPrivate] = $this->makeKeyPair();

        $this->app->make(AppSettings::class)->set('license.token', $this->token([], $otherPrivate));

        $this->assertSame(LicenseStatus::REFUSED, $this->state()->state());
        $this->assertFalse($this->state()->status()->grantsRights());
    }

    public function test_a_token_for_another_product_is_rejected(): void
    {
        $this->fakeAnswer('activate', $this->okBody($this->token(['product' => 'other'])));

        $this->assertSame('invalid_token', $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY)->error);
        $this->assertFalse($this->state()->status()->grantsRights());
    }

    public function test_the_issuer_is_checked_when_configured(): void
    {
        config(['kintai.license.issuer' => 'someone-else']);
        $this->fakeAnswer('activate', $this->okBody());

        $this->assertSame('invalid_token', $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY)->error);
    }

    public function test_an_expired_token_grants_nothing(): void
    {
        $this->fakeAnswer('activate', $this->okBody($this->token(['iat' => now()->subDays(30)->getTimestamp(), 'exp' => now()->subDay()->getTimestamp()])));

        $result = $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY);

        $this->assertSame('invalid_token', $result->error);
        $this->assertFalse($this->state()->status()->grantsRights());
    }

    public function test_an_unreachable_server_keeps_the_rights_while_the_token_is_valid(): void
    {
        $this->activate();

        foreach ([500, 429] as $status) {
            $this->fakeRaw('validate', Http::response('boom', $status));
            $this->app->make(ValidateLicense::class)->handle($this->owner);

            $this->assertSame(LicenseStatus::GRACE, $this->state()->state());
            $this->assertTrue($this->state()->status()->grantsRights());
        }

        $this->fakeRaw('validate', Http::response('not json', 200));
        $this->app->make(ValidateLicense::class)->handle($this->owner);
        $this->assertSame(LicenseStatus::GRACE, $this->state()->state());

        $this->fakeRaw('validate', fn () => throw new ConnectionException('down'));
        $this->app->make(ValidateLicense::class)->handle($this->owner);
        $this->assertSame(LicenseStatus::GRACE, $this->state()->state());
        $this->assertSame(5, $this->state()->limit('max_stores'));

        // Le jeton expire hors ligne : retour au palier gratuit.
        Carbon::setTestNow(now()->addDays(15));
        $status = $this->state()->status();
        $this->assertSame(LicenseStatus::UNREACHABLE, $status->state);
        $this->assertFalse($status->grantsRights());
        $this->assertTrue($status->isProblem());
    }

    public function test_unreachable_without_a_token_grants_nothing(): void
    {
        $this->app->make(AppSettings::class)->set('license.key', self::KEY);
        $this->fakeRaw('validate', Http::response('', 503));

        $this->app->make(ValidateLicense::class)->handle($this->owner);

        $this->assertSame(LicenseStatus::UNREACHABLE, $this->state()->state());
        $this->assertFalse($this->state()->status()->grantsRights());
    }

    public function test_a_failed_activation_while_unreachable_changes_nothing(): void
    {
        $this->fakeRaw('activate', Http::response('', 500));

        $result = $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY);

        $this->assertSame(LicenseResult::UNREACHABLE, $result->kind);
        $this->assertFalse($this->state()->hasKey());
    }

    public function test_the_other_http_codes_are_not_a_verdict(): void
    {
        $this->activate();

        foreach ([[401, 'invalid_api_key'], [422, 'missing_license_key']] as [$status, $error]) {
            $this->fakeRaw('validate', Http::response(['error' => $error], $status));
            $result = $this->app->make(ValidateLicense::class)->handle($this->owner);

            $this->assertSame(LicenseResult::REJECTED, $result->kind);
            $this->assertTrue($this->state()->status()->grantsRights());
        }
    }

    public function test_a_successful_validation_recovers_from_grace(): void
    {
        $this->activate();
        $this->fakeRaw('validate', Http::response('', 500));
        $this->app->make(ValidateLicense::class)->handle($this->owner);
        $this->assertSame(LicenseStatus::GRACE, $this->state()->state());
        $this->fakeAnswer('validate', $this->okBody());
        $this->app->make(ValidateLicense::class)->handle($this->owner);

        $this->assertSame(LicenseStatus::ACTIVE, $this->state()->state());
    }

    public function test_deactivation_frees_the_seat_and_forgets_the_license(): void
    {
        $this->activate();
        $this->fakeAnswer('deactivate', ['valid' => true]);

        $this->app->make(DeactivateLicense::class)->handle($this->owner);

        Http::assertSent(fn ($request): bool => $request->url() === self::BASE.'/deactivate' && $request['license_key'] === self::KEY);
        $this->assertSame(LicenseStatus::FREE, $this->state()->state());
        $this->assertFalse($this->state()->hasKey());
        $this->assertDatabaseHas('activity_entries', ['action' => 'license.deactivated']);
    }

    public function test_deactivation_keeps_the_license_when_the_server_is_unreachable(): void
    {
        $this->activate();
        $this->fakeRaw('deactivate', Http::response('', 500));

        $this->app->make(DeactivateLicense::class)->handle($this->owner);

        $this->assertTrue($this->state()->hasKey());
    }

    public function test_only_the_owner_can_use_the_actions(): void
    {
        $employee = User::factory()->create();
        Http::fake();

        foreach ([ActivateLicense::class => [self::KEY], ValidateLicense::class => [], DeactivateLicense::class => []] as $class => $args) {
            try {
                $this->app->make($class)->handle($employee, ...$args);
                $this->fail("{$class} must be refused");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_key_never_reaches_the_activity_log(): void
    {
        $this->activate();
        $this->fakeAnswer('validate', ['valid' => false, 'error' => 'license_revoked']);
        $this->app->make(ValidateLicense::class)->handle($this->owner);
        $this->fakeAnswer('deactivate', ['valid' => true]);
        $this->app->make(DeactivateLicense::class)->handle($this->owner);

        $this->assertGreaterThan(0, DB::table('activity_entries')->where('action', 'like', 'license.%')->count());
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('activity_entries')->get()));
    }

    public function test_the_validate_command_does_nothing_without_a_key_and_validates_with_one(): void
    {
        $this->artisan('kintai:license:validate')->assertSuccessful();
        Http::assertNothingSent();

        $this->activate();
        $this->fakeAnswer('validate', $this->okBody());
        $this->artisan('kintai:license:validate')->assertSuccessful();
        Http::assertSent(fn ($request): bool => $request->url() === self::BASE.'/validate');
    }

    public function test_the_command_is_scheduled_daily_only_with_a_key(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'kintai:license:validate'));

        $this->assertNotNull($event);
        $this->assertSame('30 5 * * *', $event->expression);
        $this->assertSame('kintai:license:validate', $event->description);
        $this->assertFalse($event->filtersPass($this->app));

        $this->activate();
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_the_buy_url_defaults_to_the_manager_page_and_can_be_set(): void
    {
        $this->assertSame('https://lic.test/public/buy/kintai', $this->state()->buyUrl());

        $this->app->make(AppSettings::class)->set('license.buy_url', 'https://shop.test/choose');
        $this->assertSame('https://shop.test/choose', $this->state()->buyUrl());
    }

    public function test_the_shipped_public_key_is_a_usable_rsa_key(): void
    {
        config(['kintai.license.public_key_b64' => (require base_path('config/kintai.php'))['license']['public_key_b64']]);

        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $this->state()->publicKey());
        $this->assertNotFalse(openssl_pkey_get_public($this->state()->publicKey()));
    }

    public function test_a_token_issued_a_few_seconds_in_the_future_is_accepted(): void
    {
        $this->app->make(AppSettings::class)->set('license.key', self::KEY);
        $now = now()->getTimestamp();

        // Horloge du serveur en avance de 2 minutes : toléré. En avance d'une heure : refusé.
        $this->assertSame(LicenseStatus::ACTIVE, $this->statusWithToken($this->token(['iat' => $now + 120])));
        $this->assertSame(LicenseStatus::REFUSED, $this->statusWithToken($this->token(['iat' => $now + 3600])));
    }

    public function test_a_token_bound_to_another_instance_is_refused(): void
    {
        $this->app->make(AppSettings::class)->setMany(['license.key' => self::KEY]);
        $mine = $this->state()->instanceId();

        $this->assertSame(LicenseStatus::ACTIVE, $this->statusWithToken($this->token(['instance_id' => $mine])));
        $this->assertSame(LicenseStatus::REFUSED, $this->statusWithToken($this->token(['instance_id' => 'autre-installation'])));
        // Jeton sans liaison : refusé.
        $this->assertSame(LicenseStatus::REFUSED, $this->statusWithToken($this->token(['instance_id' => null])));
    }

    public function test_the_verification_key_cannot_be_replaced_from_the_database(): void
    {
        [$forgedPrivate] = $this->makeKeyPair();
        $this->app->make(AppSettings::class)->set('license.key', self::KEY);

        $this->assertSame(LicenseStatus::REFUSED, $this->statusWithToken($this->token([], $forgedPrivate)));

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(AppSettings::class)->set('license.public_key', 'x');
    }

    private function statusWithToken(string $token): string
    {
        $this->app->make(AppSettings::class)->set('license.token', $token);

        return $this->state()->state();
    }

    public function test_retries_only_on_connection_errors_and_5xx(): void
    {
        $this->activate();

        foreach ([[401, 1], [422, 1], [429, 1], [500, 2], [503, 2]] as [$status, $expected]) {
            $this->fakeRaw('validate', Http::response(['error' => 'x'], $status));
            $this->app->make(ValidateLicense::class)->handle($this->owner);
            Http::assertSentCount($expected);
        }
    }

    public function test_a_503_signing_unavailable_is_unreachable_and_keeps_the_rights(): void
    {
        $this->activate();
        $this->fakeRaw('validate', Http::response(['error' => 'signing_unavailable'], 503));

        $result = $this->app->make(ValidateLicense::class)->handle($this->owner);

        $this->assertSame(LicenseResult::UNREACHABLE, $result->kind);
        $this->assertSame(LicenseStatus::GRACE, $this->state()->state());
        $this->assertFalse($this->state()->status()->productKeyRejected());
    }

    public function test_a_refused_product_key_is_a_distinct_diagnostic_and_keeps_the_rights(): void
    {
        $this->activate();

        foreach (['invalid_api_key', 'missing_api_key'] as $error) {
            $this->fakeRaw('validate', Http::response(['error' => $error], 401));
            $result = $this->app->make(ValidateLicense::class)->handle($this->owner);

            $this->assertTrue($result->isProductKeyRejected());
            $status = $this->state()->status();
            $this->assertTrue($status->productKeyRejected());
            $this->assertSame(LicenseStatus::GRACE, $status->state);
            $this->assertTrue($status->grantsRights());
            $this->assertSame(5, $this->state()->limit('max_stores'));
        }

        $this->artisan('kintai:license:validate')->expectsOutputToContain('The product key is refused by the license server.')->assertSuccessful();

        // Le jeton expire sans que la clé produit soit corrigée : plus de droits, alerte avec la bonne cause.
        Carbon::setTestNow(now()->addDays(15));
        $status = $this->state()->status();
        $this->assertSame(LicenseStatus::UNREACHABLE, $status->state);
        $this->assertTrue($status->productKeyRejected());
        $alert = $this->app->make(CoreSystemAlerts::class)->licenseProblem();
        $this->assertSame(CoreSystemAlerts::LICENSE_PROBLEMS['product_key_rejected'], collect(CoreSystemAlerts::LICENSE_PROBLEMS)->first(fn ($label): bool => __($label) === $alert->params['problem']));

        // Une réponse valable efface le diagnostic.
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->fakeAnswer('validate', $this->okBody());
        $this->app->make(ValidateLicense::class)->handle($this->owner);
        $this->assertSame(LicenseStatus::ACTIVE, $this->state()->state());
        $this->assertFalse($this->state()->status()->productKeyRejected());
    }

    public function test_a_valid_answer_without_a_usable_token_is_unreachable_not_a_refusal(): void
    {
        $this->activate();
        $stored = $this->app->make(AppSettings::class)->string('license.token');

        foreach ([[], ['license_token' => 'not-a-jwt']] as $override) {
            $body = $this->okBody();
            unset($body['license_token']);
            $this->fakeRaw('validate', Http::response($override + $body));

            $result = $this->app->make(ValidateLicense::class)->handle($this->owner);

            $this->assertSame(LicenseResult::UNREACHABLE, $result->kind);
            $this->assertSame(LicenseStatus::GRACE, $this->state()->state());
            $this->assertSame($stored, $this->app->make(AppSettings::class)->string('license.token'));
        }

        // À l'activation : rien n'est retenu, et le message reste explicite.
        $this->app->make(AppSettings::class)->forget('license.key', 'license.token');
        $body = $this->okBody();
        unset($body['license_token']);
        $this->fakeRaw('activate', Http::response($body));
        $result = $this->app->make(ActivateLicense::class)->handle($this->owner, self::KEY);
        $this->assertSame('invalid_token', $result->error);
        $this->assertFalse($this->state()->hasKey());
    }
}
