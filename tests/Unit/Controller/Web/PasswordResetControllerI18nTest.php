<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\CredentialRevoker;
use App\Mail\MailerService;
use App\Models\ApiToken;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\AppSetting;
use App\Services\AppSettingsService;
use App\Services\PasswordResetService;
use App\Services\PublicUrlResolver;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Les titres et les messages d'erreur du parcours « mot de passe oublié » passent par des clés i18n
 * (lang/*.json) au lieu d'être écrits en français dans le contrôleur : un employé anglophone ou japonais
 * ne doit pas tomber sur du français au moment où il récupère son compte.
 *
 * Sans conteneur, __() renvoie la clé : les tests vérifient donc que le contrôleur transmet bien des clés.
 */
final class PasswordResetControllerI18nTest extends TestCase
{
    private ?int $expectedTokens = null;
    private PasswordResetController $controller;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-pwreset-i18n-' . bin2hex(random_bytes(4));
        // Les vues factices affichent exactement ce que le contrôleur leur transmet.
        $this->writeView($viewDir, 'auth/forgot-password', '<?= "TITLE=" . $title ?>');
        $this->writeView($viewDir, 'auth/reset-password', '<?= "TITLE=" . $title . "|ERROR=" . ($error ?? "") ?>');
        $this->writeView($viewDir, 'layout/guest', '<?= $content ?>');

        kintai_test_database();

        kintai_test_migrate();

        $service = new PasswordResetService(
            new MailerService(['default' => 'sendmail', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
            new CredentialRevoker(
            ),
        );
        kintai_test_settings([]);
        $this->controller = new PasswordResetController(
            kintai_test_views($viewDir),
            $service,
            new PublicUrlResolver(new AppSettingsService(), 'https://kintai.example.com'),
        );
    }

    private function writeView(string $dir, string $view, string $content): void
    {
        $file = $dir . '/' . $view . '.php';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    private function resetRequest(string $password, string $confirm): Request
    {
        $_POST = ['password' => $password, 'password_confirmation' => $confirm];
        $request = Request::capture();
        $request->setRouteParams(['token' => 'tok']);
        $_POST = [];
        return $request;
    }

    private function validRecord(): array
    {
        return ['id' => 1, 'email' => 'user@test.com', 'expires_at' => date('Y-m-d H:i:s', time() + 3600)];
    }

    public function testForgotFormTitleIsATranslationKey(): void
    {
        $body = $this->controller->showForgotForm(Request::capture())->getContent();

        $this->assertStringContainsString('TITLE=forgot_password_title', $body);
    }

    public function testResetFormTitleIsATranslationKey(): void
    {
        $this->seedToken($this->validRecord());
        $request = Request::capture();
        $request->setRouteParams(['token' => 'tok']);

        $this->assertStringContainsString('TITLE=new_password', $this->controller->showResetForm($request)->getContent());
    }

    public function testTooShortPasswordErrorIsATranslationKey(): void
    {
        $this->seedToken($this->validRecord());

        $body = $this->controller->reset($this->resetRequest('short', 'short'))->getContent();

        $this->assertStringContainsString('ERROR=reset_password_too_short', $body);
    }

    public function testMismatchErrorIsATranslationKey(): void
    {
        $this->seedToken($this->validRecord());

        $body = $this->controller->reset($this->resetRequest('long-enough-1', 'long-enough-2'))->getContent();

        $this->assertStringContainsString('ERROR=password_mismatch', $body);
    }

    public function testInvalidLinkErrorIsATranslationKey(): void
    {
        // Jeton inconnu : la vérification finale du service échoue.

        $body = $this->controller->reset($this->resetRequest('long-enough-1', 'long-enough-1'))->getContent();

        $this->assertStringContainsString('ERROR=reset_password_invalid_link', $body);
    }

    public function testNoFrenchLiteralRemainsInTheControllerOrItsView(): void
    {
        // Garde-fou contre le retour d'un texte en dur : ni accent, ni mot français courant.
        foreach ([
            'app/Http/Controllers/PasswordResetController.php',
            'resources/views/auth/reset-password.blade.php',
            'resources/views/auth/forgot-password.blade.php',
        ] as $file) {
            $code = (string) file_get_contents(dirname(__DIR__, 4) . '/' . $file);
            // On ignore les commentaires (écrits en français par convention du projet).
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code);
            $this->assertDoesNotMatchRegularExpression('/[éèêàùçô]|Nouveau|Enregistrer|Se connecter|Confirmer/u', $code, $file);
        }
    }

    public function testEveryNewKeyExistsInAllThreeLanguages(): void
    {
        foreach (['fr', 'en', 'ja'] as $lang) {
            $messages = json_decode((string) file_get_contents(dirname(__DIR__, 4) . "/lang/{$lang}.json"), true);
            foreach (['forgot_password_title', 'new_password', 'confirm_password', 'new_request', 'connect',
                      'password_mismatch', 'reset_password_invalid_link', 'reset_password_too_short', 'reset_password_submit'] as $key) {
                $this->assertArrayHasKey($key, $messages, "{$lang}.json : clé « {$key} » manquante");
            }
        }
    }

    /** Enregistre un jeton de réinitialisation tel que le service le stocke (empreinte du jeton). */
    private function seedToken(array $record): void
    {
        PasswordResetToken::create([
            'email'      => $record['email'],
            'token'      => hash_token($record['token'] ?? 'tok'),
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
