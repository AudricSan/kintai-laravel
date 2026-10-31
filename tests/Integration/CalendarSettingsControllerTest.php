<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Shift;
use App\Models\AppSetting;
use App\Models\CalendarConnection;
use App\Auth\UserAnonymizer;
use App\Auth\CredentialRevoker;
use App\Models\RoleAssignment;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleRedirectUri;
use App\Services\PublicUrlResolver;
use App\Models\ApiToken;
use App\Models\User;
use Tests\Support\CalendarSyncFixture;
use App\Http\Controllers\System\CalendarSettingsController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/** Identifiants Google de l'instance (Owner), test des identifiants, et nettoyage à la suppression d'un compte. */
final class CalendarSettingsControllerTest extends TestCase
{
    use CalendarSyncFixture;

    private CalendarSettingsController $controller;
    private ViewFactory $view;

    protected function setUp(): void
    {
        $this->setUpCalendarFixture();
        session()->put('_token', 'x');
        $router = kintai_test_registrar();
        // Routes citées par la page et par le bandeau d'onglets des réglages.
        foreach (['x', 'admin.owner_settings', 'admin.notifications_settings', 'admin.calendar_settings', 'admin.calendar_settings.save', 'admin.calendar_settings.test', 'admin.backup', 'admin.update', 'admin.languages', 'admin.bundles', 'admin.roles', 'admin.license', 'home'] as $i => $name) {
            $router->get('/p' . $i, ['X', 'y'], name: $name);
        }
        $this->view = kintai_test_views(BASE_PATH . '/resources/views');
        $this->view->share('BASE_URL', '');
        $this->controller = new CalendarSettingsController(
            $this->view,
            $this->google,
            new GoogleCalendarClient($this->google),
            new GoogleRedirectUri(new PublicUrlResolver($this->appSettings, 'https://kintai.test')),
            new AuditLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownCalendarFixture();
        $_POST = [];
        $_GET  = [];
    }

    private function location(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return (string) (kintai_test_headers($response)['Location'] ?? '');
    }

    public function testSavingKeepsTheSecretWhenTheFieldIsLeftEmptyAndEncryptsANewOne(): void
    {
        $_POST = ['google_calendar_enabled' => '1', 'google_calendar_client_id' => ' new-id ', 'google_calendar_client_secret' => ''];
        $this->controller->save(Request::capture());
        $this->assertSame('new-id', $this->google->clientId());
        $this->assertSame('client-secret', $this->google->clientSecret(), 'champ vide : secret conservé');

        $_POST = ['google_calendar_enabled' => '1', 'google_calendar_client_id' => 'new-id', 'google_calendar_client_secret' => 'brand-new-secret'];
        $this->controller->save(Request::capture());
        $this->assertSame('brand-new-secret', $this->google->clientSecret());
        $this->assertStringNotContainsString('brand-new-secret', (string) AppSetting::value('google_calendar_client_secret'));
    }

    public function testUncheckingTheBoxDisablesTheFeatureWithoutLosingTheCredentials(): void
    {
        $_POST = ['google_calendar_client_id' => 'client-id'];

        $this->controller->save(Request::capture());

        $this->assertFalse($this->google->isAvailable());
        $this->assertTrue($this->google->hasSecret());
    }

    public function testTheSecretNeverReachesTheRenderedPage(): void
    {
        // La page sans la mise en page de l'application (qui demande toutes les routes du site).
        $html = $this->view->make('system.calendar-settings', [
            'enabled' => true, 'client_id' => 'client-id', 'has_secret' => true, 'available' => true,
            'redirect_uri' => 'https://kintai.test/profile/calendar/google/callback', 'connections' => 2, 'check' => '',
        ])->render();

        $this->assertStringNotContainsString('client-secret', $html);
        $this->assertStringContainsString('https://kintai.test/profile/calendar/google/callback', $html);
        $this->assertStringContainsString('value="client-id"', $html);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit)=|\sstyle="/i', $html);
    }

    /** @return array<string, array{array{status: int, body: string}, string}> */
    public static function credentialChecks(): array
    {
        return [
            'identifiants bons, code faux'  => [['status' => 400, 'body' => '{"error":"invalid_grant"}'], 'ok'],
            'identifiants refusés'          => [['status' => 401, 'body' => '{"error":"invalid_client"}'], 'invalid_client'],
            'Google injoignable'            => [['status' => 0, 'body' => ''], 'unreachable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('credentialChecks')]
    public function testTheCredentialCheckDistinguishesBadCredentialsFromABadCode(array $googleAnswer, string $expected): void
    {
        $this->http->when('POST', '/token', $googleAnswer);

        $response = $this->controller->test(Request::capture());

        $this->assertStringEndsWith('?check=' . $expected, $this->location($response));
    }

    public function testTheCheckAsksToFinishTheSetupWhenCredentialsAreMissing(): void
    {
        AppSetting::put('google_calendar_client_secret', '');

        $response = $this->controller->test(Request::capture());

        $this->assertStringEndsWith('?check=incomplete', $this->location($response));
        $this->assertCount(0, $this->http->calls('POST', '/token'));
    }

    public function testDeletingAnAccountRemovesItsLinkAndRevokesTheTokenAtGoogle(): void
    {
        Shift::put(['store_id' => 1, 'user_id' => $this->alice, 'shift_type_id' => 1, 'shift_date' => $this->day, 'start_time' => '09:00', 'end_time' => '17:00', 'starts_at' => $this->day . ' 09:00:00', 'ends_at' => $this->day . ' 17:00:00']);
        $this->sync->connect($this->alice, 'RA', null);
        $this->sync->processQueue();
        kintai_test_assignments(findByUser: fn() => []);
        $anonymizer = new UserAnonymizer(
            new CredentialRevoker(),
            sys_get_temp_dir(),
            $this->sync,
        );

        $anonymizer->anonymize($this->alice);

        $this->assertNull(CalendarConnection::ofUser($this->alice));
        $this->assertCount(1, $this->http->calls('DELETE', '/events/evt-1'), 'les événements ne suivent plus un compte supprimé');
        $this->assertCount(1, $this->http->callsEndingWith('POST', '/revoke'));
        $this->assertSame(0, $this->capsule->table('calendar_event_links')->count());
    }

    public function testHardDeletingAUserPurgesTheCalendarRows(): void
    {
        $this->sync->connect($this->alice, 'RA', null);

        User::remove($this->alice);

        foreach (['calendar_connections', 'calendar_event_links', 'calendar_sync_queue'] as $table) {
            $this->assertSame(0, $this->capsule->table($table)->count(), $table);
        }
    }
}
