<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Http\Controllers\CronController;
use App\Models\CronToken;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppSettings;

/**
 * Point d'entrée générique /cron/run/{job} : un jeton valide lance la commande Artisan kintai:{job} si le planificateur
 * la planifie ; rien d'autre n'est lançable par adresse.
 */
final class CronControllerTest extends TestCase
{
    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(1);
        app()->instance(AppSettingsService::class, new AppSettingsService(new InMemoryAppSettings(['log_retention_days' => '0'])));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(AppSettingsService::class);
    }

    private function request(string $job, array $query = [], array $headers = []): Request
    {
        $request = Request::create('/cron/run/' . $job, 'GET', $query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $request->setRouteParams(['job' => $job]);

        return $request;
    }

    public function testAValidTokenRunsTheScheduledCommand(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => 'log-purge', 'token' => hash_token('raw-token')]);

        $response = (new CronController())->run($this->request('log-purge', ['token' => 'raw-token']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getContent(), true)['ok'], 'rétention illimitée : rien à purger');
        $this->assertNotNull(CronToken::first()->last_used_at);
    }

    public function testABearerTokenWorksLikeTheQueryToken(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => '', 'token' => hash_token('bearer-token')]);

        $response = (new CronController())->run($this->request('log-purge', [], ['Authorization' => 'Bearer bearer-token']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAMissingTokenIsRefused(): void
    {
        $this->expectException(ForbiddenException::class);
        (new CronController())->run($this->request('log-purge'));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => '', 'token' => hash_token('old'), 'expires_at' => '2020-01-01 00:00:00']);

        $this->expectException(ForbiddenException::class);
        (new CronController())->run($this->request('log-purge', ['token' => 'old']));
    }

    public function testATokenScopedToAnotherJobIsRefused(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => 'backup', 'token' => hash_token('raw-token')]);

        $this->expectException(ForbiddenException::class);
        (new CronController())->run($this->request('log-purge', ['token' => 'raw-token']));
    }

    public function testACommandThatIsNotScheduledCannotBeRunByAddress(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => '', 'token' => hash_token('raw-token')]);

        $this->expectException(NotFoundException::class);
        (new CronController())->run($this->request('cron-token', ['token' => 'raw-token']));
    }
}
