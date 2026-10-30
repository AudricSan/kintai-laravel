<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Mail\MailerService;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Models\PasswordResetToken;
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
    private ?int $expectedTokens = null;
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

        kintai_test_database();

        kintai_test_migrate();
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
            $this->users,
            new MailerService(['default' => 'sendmail', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
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
        $this->expectedTokens = 1;

        $body = $this->controller('https://kintai.example.com')->sendLink($this->request())->getContent();

        $this->assertSame('SENT', $body);
    }

    public function testNothingIsSentWithoutAPublicUrlEvenIfTheRequestHasAHostHeader(): void
    {
        $this->expectedTokens = 0;

        $body = $this->controller('')->sendLink($this->request())->getContent();

        // Même page « envoyé » qu'avec un compte inconnu : pas d'énumération possible.
        $this->assertSame('SENT', $body);
    }

    /** Enregistre un jeton de réinitialisation tel que le service le stocke (empreinte du jeton). */
    private function seedToken(array $record): void
    {
        PasswordResetToken::create([
            'email'      => $record['email'],
            'token'      => hash_token($record['token']),
            'expires_at' => $record['expires_at'],
        ]);
    }

    protected function assertPostConditions(): void
    {
        if ($this->expectedTokens !== null) {
            $this->assertSame($this->expectedTokens, PasswordResetToken::count(), 'jetons de réinitialisation enregistrés');
        }
    }
}
