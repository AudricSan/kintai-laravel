<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AuthService;
use App\Cron\CalendarSyncJob;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleRedirectUri;
use App\Services\PublicUrlResolver;
use App\SessionFlash;
use Tests\Support\CalendarSyncFixture;
use App\Http\Controllers\CalendarLinkController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Liaison Google Calendar de l'utilisateur connecté : flux OAuth (state + PKCE à usage unique), refus, déconnexion,
 * choix du nom de l'agenda. Chacun ne touche que sa propre liaison.
 */
final class CalendarLinkControllerTest extends TestCase
{
    use CalendarSyncFixture;

    private CalendarLinkController $controller;
    private ?array $membership = ['id' => 1];

    protected function setUp(): void
    {
        $this->setUpCalendarFixture();
        session()->put(auth()->getName(), $this->alice);
        session()->forget(['_kintai_gcal_oauth', '_kintai_flash']);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => ['id' => $id, 'email' => 'alice@kintai.test', 'language' => 'fr', 'is_active' => 1, 'deleted_at' => null]);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findMembership')->willReturnCallback(fn() => $this->membership);

        $auth = new AuthService(
            $users, $storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(UserRepositoryInterface::class),
        );

        $this->controller = new CalendarLinkController(
            $auth, $this->google, new GoogleCalendarClient($this->google, $this->http), $this->sync, $this->repo, $storeUsers,
            new GoogleRedirectUri(new PublicUrlResolver($this->appSettings, 'https://kintai.test')),
            new AuditLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownCalendarFixture();
        session()->forget([auth()->getName(), '_kintai_gcal_oauth', '_kintai_flash']);
        $_POST = [];
        $_GET  = [];
    }

    private function location(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return (string) (kintai_test_headers($response)['Location'] ?? '');
    }

    /** Lance la liaison et renvoie le state envoyé à Google. */
    private function startOauth(array $post = []): string
    {
        $_POST = $post;
        $response = $this->controller->connect(Request::capture());
        parse_str((string) parse_url($this->location($response), PHP_URL_QUERY), $q);
        return (string) $q['state'];
    }

    private function returnFromGoogle(array $query): \Symfony\Component\HttpFoundation\Response
    {
        $_GET = $query;
        return $this->controller->callback(Request::capture());
    }

    private function flashTypes(): array
    {
        return array_column(SessionFlash::pull(), 'type');
    }

    public function testConnectRedirectsToGoogleWithPkceAndStoresStateServerSide(): void
    {
        $_POST = [];
        $response = $this->controller->connect(Request::capture());

        $url = $this->location($response);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://kintai.test/profile/calendar/google/callback', $q['redirect_uri']);
        $this->assertSame(session()->get('_kintai_gcal_oauth')['state'], $q['state']);
        $this->assertSame(GoogleCalendarClient::codeChallenge(session()->get('_kintai_gcal_oauth')['verifier']), $q['code_challenge']);
        $this->assertStringNotContainsString(session()->get('_kintai_gcal_oauth')['verifier'], $url, 'le vérificateur PKCE ne quitte jamais le serveur');
    }

    public function testConnectIsRefusedWhenTheOwnerHasNotEnabledTheFeature(): void
    {
        $this->google->save(false, 'client-id', null);

        $response = $this->controller->connect(Request::capture());

        $this->assertStringEndsWith('/profile?tab=ical', $this->location($response));
        $this->assertFalse(session()->has('_kintai_gcal_oauth'));
        $this->assertSame(['warning'], $this->flashTypes());
    }

    public function testSuccessfulCallbackLinksTheAccountAndQueuesTheFirstSync(): void
    {
        $this->shifts->save(['store_id' => 1, 'user_id' => $this->alice, 'shift_type_id' => 1, 'shift_date' => $this->day, 'start_time' => '09:00', 'end_time' => '17:00', 'starts_at' => $this->day . ' 09:00:00', 'ends_at' => $this->day . ' 17:00:00']);
        $this->http->when('POST', '/token', ['status' => 200, 'body' => json_encode(['refresh_token' => 'REFRESH', 'access_token' => 'A'])]);
        $state = $this->startOauth();

        $response = $this->returnFromGoogle(['state' => $state, 'code' => 'the-code']);

        $this->assertStringEndsWith('/profile?tab=ical', $this->location($response));
        $connection = $this->repo->findConnectionByUser($this->alice);
        $this->assertSame('active', $connection['status']);
        $this->assertStringStartsWith('enc1:', $connection['refresh_token']);
        $this->assertCount(1, $this->repo->dueQueue(10), 'le shift à venir est déjà en file');
        $this->assertSame(['success'], $this->flashTypes());
        $this->assertFalse(session()->has('_kintai_gcal_oauth'), 'le state est à usage unique');
    }

    public function testCallbackWithAForgedOrMissingStateIsRejected(): void
    {
        $this->startOauth();

        $this->returnFromGoogle(['state' => 'forged', 'code' => 'c']);

        $this->assertNull($this->repo->findConnectionByUser($this->alice));
        $this->assertSame(['danger'], $this->flashTypes());
        $this->assertCount(0, $this->http->calls('POST', '/token'), 'aucun échange de code avec un state invalide');

        // Retour de Google sans avoir jamais lancé la liaison dans cette session (autre session, rejeu).
        $this->returnFromGoogle(['state' => 'whatever', 'code' => 'c']);
        $this->assertNull($this->repo->findConnectionByUser($this->alice));
    }

    public function testAStateCannotBeReplayed(): void
    {
        $this->http->when('POST', '/token', ['status' => 200, 'body' => json_encode(['refresh_token' => 'R'])]);
        $state = $this->startOauth();
        $this->returnFromGoogle(['state' => $state, 'code' => 'c']);
        $this->repo->deleteAllForUser($this->alice);

        $this->returnFromGoogle(['state' => $state, 'code' => 'c']);

        $this->assertNull($this->repo->findConnectionByUser($this->alice));
    }

    public function testAnExpiredStateIsRejected(): void
    {
        $state = $this->startOauth();
        session()->put('_kintai_gcal_oauth.at', time() - 3600);

        $this->returnFromGoogle(['state' => $state, 'code' => 'c']);

        $this->assertNull($this->repo->findConnectionByUser($this->alice));
    }

    public function testRefusingTheConsentLinksNothing(): void
    {
        $state = $this->startOauth();

        $this->returnFromGoogle(['state' => $state, 'error' => 'access_denied']);

        $this->assertNull($this->repo->findConnectionByUser($this->alice));
        $this->assertSame(['warning'], $this->flashTypes());
    }

    public function testGoogleRefusingTheCodeShowsAnErrorAndLinksNothing(): void
    {
        $this->http->when('POST', '/token', ['status' => 400, 'body' => json_encode(['error' => 'invalid_grant'])]);
        $state = $this->startOauth();

        $this->returnFromGoogle(['state' => $state, 'code' => 'bad']);

        $this->assertNull($this->repo->findConnectionByUser($this->alice));
        $this->assertSame(['danger'], $this->flashTypes());
    }

    public function testTheCalendarCanBeNamedAfterOneOfMyStoresButNotAnotherOne(): void
    {
        $this->http->when('POST', '/token', ['status' => 200, 'body' => json_encode(['refresh_token' => 'R'])]);
        $state = $this->startOauth(['calendar_name_mode' => 'store', 'store_id' => '1']);
        $this->returnFromGoogle(['state' => $state, 'code' => 'c']);
        $options = json_decode((string) $this->repo->findConnectionByUser($this->alice)['options'], true);
        $this->assertSame(['store', 1], [$options['calendar_name_mode'], $options['store_id']]);

        // Un magasin où la personne ne travaille pas : repli sur le nom de l'entreprise.
        $this->membership = null;
        $state = $this->startOauth(['calendar_name_mode' => 'store', 'store_id' => '99']);
        $this->returnFromGoogle(['state' => $state, 'code' => 'c']);
        $options = json_decode((string) $this->repo->findConnectionByUser($this->alice)['options'], true);
        $this->assertSame(['instance', 0], [$options['calendar_name_mode'], $options['store_id']]);
    }

    public function testDisconnectOnlyTouchesMyOwnLink(): void
    {
        $this->sync->connect($this->alice, 'RA', null);
        $this->sync->connect($this->bob, 'RB', null);
        $_POST = ['user_id' => (string) $this->bob];

        $this->controller->disconnect(Request::capture());

        $this->assertNull($this->repo->findConnectionByUser($this->alice));
        $this->assertNotNull($this->repo->findConnectionByUser($this->bob), 'aucun identifiant venu de la requête ne désigne la cible');
    }

    public function testOptionsRenameTheCalendarAtTheNextSync(): void
    {
        $this->sync->connect($this->alice, 'RA', null);
        $this->sync->processQueue();
        $_POST = ['calendar_name_mode' => 'store', 'store_id' => '1'];

        $this->controller->options(Request::capture());
        $this->sync->processQueue();

        $this->assertSame('Shibuya', $this->repo->findConnectionByUser($this->alice)['calendar_name']);
        $this->assertCount(1, $this->http->calls('PATCH', '/calendars/cal-1'));
    }

    public function testResyncDoesNothingForARevokedLink(): void
    {
        $connection = $this->sync->connect($this->alice, 'RA', null);
        $this->repo->updateConnection((int) $connection['id'], ['status' => 'revoked']);
        $this->repo->removeFromQueue(1);
        $_POST = [];

        $this->controller->resync(Request::capture());

        $this->assertSame([], $this->repo->dueQueue(10));
    }

    public function testTheCronJobDoesNothingWhileTheFeatureIsDisabledThenProcessesTheQueue(): void
    {
        $this->shifts->save(['store_id' => 1, 'user_id' => $this->alice, 'shift_type_id' => 1, 'shift_date' => $this->day, 'start_time' => '09:00', 'end_time' => '17:00', 'starts_at' => $this->day . ' 09:00:00', 'ends_at' => $this->day . ' 17:00:00']);
        $this->sync->connect($this->alice, 'RA', null);
        $job = new CalendarSyncJob($this->repo, $this->sync, $this->google);

        $this->google->save(false, 'client-id', null);
        $disabled = json_decode($job->run(Request::capture())->getContent(), true);
        $this->assertFalse($disabled['enabled']);
        $this->assertCount(0, $this->http->calls('POST', '/events'));

        $this->google->save(true, 'client-id', null);
        $enabled = json_decode($job->run(Request::capture())->getContent(), true);
        $this->assertTrue($enabled['enabled']);
        $this->assertSame(1, $enabled['processed']);
        $this->assertCount(1, $this->http->calls('POST', '/events'));
    }

    public function testTheJobNameIsReservedForTheCore(): void
    {
        $job = new CalendarSyncJob($this->repo, $this->sync, $this->google);

        $this->assertSame('calendar-sync', $job->getName());
        $this->assertContains('calendar-sync', \App\Cron\CronRunner::CORE_JOBS);
    }
    // -------------------------------------------------------------------------
    // Encart du profil
    // -------------------------------------------------------------------------

    private function panel(array $calendar): string
    {
        session()->put('_token', 'x');
        $router = kintai_test_registrar();
        foreach (['profile.calendar.connect', 'profile.calendar.options', 'profile.calendar.resync', 'profile.calendar.disconnect'] as $i => $name) {
            $router->post('/p' . $i, ['X', 'y'], name: $name);
        }
        $view = kintai_test_views(BASE_PATH . '/resources/views');
        $view->share('BASE_URL', '');

        return $view->make('auth._profile-google-calendar', ['calendar' => $calendar, 'stores' => [1 => ['id' => 1, 'name' => 'Shibuya']]])->render();
    }

    public function testThePanelIsHiddenUntilTheOwnerEnablesTheFeature(): void
    {
        $this->assertSame('', trim($this->panel(['available' => false, 'connection' => null])));
    }

    public function testThePanelOffersToConnectThenShowsTheStateWithoutAnyToken(): void
    {
        $offer = $this->panel(['available' => true, 'connection' => null]);
        $this->assertStringContainsString('gcal_connect', $offer);
        $this->assertStringContainsString('Shibuya', $offer);

        $connection = ['status' => 'revoked', 'email' => 'moi@example.com', 'calendar_name' => 'Sakura Co.', 'last_sync_at' => null, 'last_error' => null, 'name_mode' => 'instance', 'store_id' => 0];
        $state = $this->panel(['available' => false, 'connection' => $connection]);
        $this->assertStringContainsString('moi@example.com', $state, 'une liaison existante reste visible même si la fonction a été désactivée');
        $this->assertStringContainsString('gcal_reconnect', $state);
        $this->assertStringContainsString('gcal_revoked_notice', $state);
        $this->assertStringNotContainsString('refresh', $state);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit)=|\sstyle="/i', $state);
    }
}
