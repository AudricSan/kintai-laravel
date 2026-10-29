<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Mail\MailerService;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Repositories\PasswordResetRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AppSettingsService;
use App\Services\PasswordResetService;
use App\Services\PublicUrlResolver;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * « Mot de passe oublié » : le lien envoyé doit reposer sur l'URL publique configurée. Sans elle, rien n'est
 * envoyé ; et l'en-tête Host de la requête n'est jamais utilisé à la place, même quand il est présent.
 */
final class PasswordResetControllerPublicUrlTest extends TestCase
{
    private PasswordResetRepositoryInterface&MockObject $resets;
    private UserRepositoryInterface&Stub $users;
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/kintai-pwreset-url-' . bin2hex(random_bytes(4));
        foreach (['auth/forgot-password' => '<?= !empty($sent) ? "SENT" : "FORM" ?>', 'layout/guest' => '<?= $content ?>'] as $view => $body) {
            $file = $this->viewDir . '/' . $view . '.php';
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $body);
        }

        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        $this->users  = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findByEmail')->willReturn([
            'id' => 7, 'email' => 'user@test.com', 'display_name' => 'User', 'is_active' => 1, 'deleted_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_HOST']);
    }

    private function controller(string $setting, string $env = ''): PasswordResetController
    {
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn($setting === '' ? [] : [PublicUrlResolver::SETTING_KEY => $setting]);

        $service = new PasswordResetService(
            $this->resets,
            $this->users,
            new MailerService(['driver' => 'native', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
        );

        return new PasswordResetController(kintai_test_views($this->viewDir), $service, new PublicUrlResolver(new AppSettingsService($repo), $env));
    }

    private function request(): Request
    {
        $_POST = ['email' => 'user@test.com'];
        // Un attaquant choisit cet en-tête : il ne doit jamais servir à construire le lien.
        $_SERVER['HTTP_HOST'] = 'evil.example.net';

        return Request::capture();
    }

    public function testAResetTokenIsCreatedWhenAPublicUrlIsConfigured(): void
    {
        $this->resets->expects($this->once())->method('create');

        $body = $this->controller('https://kintai.example.com')->sendLink($this->request())->getContent();

        $this->assertSame('SENT', $body);
    }

    public function testNothingIsSentWithoutAPublicUrlEvenIfTheRequestHasAHostHeader(): void
    {
        $this->resets->expects($this->never())->method('create');

        $body = $this->controller('')->sendLink($this->request())->getContent();

        // Même page « envoyé » qu'avec un compte inconnu : pas d'énumération possible.
        $this->assertSame('SENT', $body);
    }
}
