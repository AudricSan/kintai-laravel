<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\PasswordResetRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\PasswordResetService;
use kintai\Core\Services\PublicUrlResolver;
use kintai\UI\Controller\Web\PasswordResetController;
use kintai\UI\ViewRenderer;
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
    private PasswordResetRepositoryInterface&Stub $resets;
    private UserRepositoryInterface&Stub $users;
    private PasswordResetController $controller;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-pwreset-i18n-' . bin2hex(random_bytes(4));
        // Les vues factices affichent exactement ce que le contrôleur leur transmet.
        $this->writeView($viewDir, 'auth/forgot-password', '<?= "TITLE=" . $title ?>');
        $this->writeView($viewDir, 'auth/reset-password', '<?= "TITLE=" . $title . "|ERROR=" . ($error ?? "") ?>');
        $this->writeView($viewDir, 'layout/guest', '<?= $content ?>');

        $this->resets = $this->createStub(PasswordResetRepositoryInterface::class);
        $this->users  = $this->createStub(UserRepositoryInterface::class);

        $service = new PasswordResetService(
            $this->resets,
            $this->users,
            new MailerService(['driver' => 'native', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
            new CredentialRevoker(
                $this->createStub(RememberTokenRepositoryInterface::class),
                $this->createStub(ApiTokenRepositoryInterface::class),
            ),
        );

        $settingsRepo = $this->createStub(AppSettingsRepositoryInterface::class);
        $settingsRepo->method('all')->willReturn([]);
        $this->controller = new PasswordResetController(
            new ViewRenderer($viewDir),
            $service,
            new PublicUrlResolver(new AppSettingsService($settingsRepo), 'https://kintai.example.com'),
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
        $request = new Request();
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
        $body = $this->controller->showForgotForm(new Request())->body();

        $this->assertStringContainsString('TITLE=forgot_password_title', $body);
    }

    public function testResetFormTitleIsATranslationKey(): void
    {
        $this->resets->method('findByToken')->willReturn($this->validRecord());
        $request = new Request();
        $request->setRouteParams(['token' => 'tok']);

        $this->assertStringContainsString('TITLE=new_password', $this->controller->showResetForm($request)->body());
    }

    public function testTooShortPasswordErrorIsATranslationKey(): void
    {
        $this->resets->method('findByToken')->willReturn($this->validRecord());

        $body = $this->controller->reset($this->resetRequest('short', 'short'))->body();

        $this->assertStringContainsString('ERROR=reset_password_too_short', $body);
    }

    public function testMismatchErrorIsATranslationKey(): void
    {
        $this->resets->method('findByToken')->willReturn($this->validRecord());

        $body = $this->controller->reset($this->resetRequest('long-enough-1', 'long-enough-2'))->body();

        $this->assertStringContainsString('ERROR=password_mismatch', $body);
    }

    public function testInvalidLinkErrorIsATranslationKey(): void
    {
        // Jeton inconnu : la vérification finale du service échoue.
        $this->resets->method('findByToken')->willReturn(null);

        $body = $this->controller->reset($this->resetRequest('long-enough-1', 'long-enough-1'))->body();

        $this->assertStringContainsString('ERROR=reset_password_invalid_link', $body);
    }

    public function testNoFrenchLiteralRemainsInTheControllerOrItsView(): void
    {
        // Garde-fou contre le retour d'un texte en dur : ni accent, ni mot français courant.
        foreach ([
            'app/UI/Controller/Web/PasswordResetController.php',
            'app/UI/View/auth/reset-password.php',
            'app/UI/View/auth/forgot-password.php',
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
}
