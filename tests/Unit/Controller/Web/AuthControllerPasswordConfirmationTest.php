<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\PasswordPolicy;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\UserNavPref;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * saveProfilePassword() et deleteAccount() doivent toujours vérifier le mot de
 * passe actuel : un password_hash vide ne dispense plus de cette vérification
 * (password_verify() contre '' renvoie false, donc l'action est refusée).
 */
final class AuthControllerPasswordConfirmationTest extends TestCase
{
    private const USER_ID = 7;
    private AuthController $controller;
    private string $rightPassword;
    private string $wrongPassword;
    private string $newPassword;

    protected function setUp(): void
    {
        session()->put(auth()->getName(), self::USER_ID);

        // Valeurs générées à l'exécution : aucun mot de passe en clair dans le dépôt.
        $this->rightPassword = bin2hex(random_bytes(8));
        $this->wrongPassword = bin2hex(random_bytes(8));
        $this->newPassword   = bin2hex(random_bytes(8));
        kintai_test_assignments(findByUser: fn() => []);
        $auth = new AuthService(
        );

        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    public function testPasswordChangeRefusedWhenStoredHashIsEmpty(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::USER_ID, 'password_hash' => '']);
        $hashBefore = (string) \App\Models\User::findById(self::USER_ID)['password_hash'];
        $_POST = ['current_password' => $this->wrongPassword, 'new_password' => $this->newPassword, 'confirm_password' => $this->newPassword];
        $this->controller->saveProfilePassword(Request::capture());
        $this->assertSame($hashBefore, (string) \App\Models\User::findById(self::USER_ID)['password_hash'], 'mot de passe inchangé');
    }

    public function testPasswordChangeRefusedWhenCurrentPasswordIsWrong(): void
    {
        kintai_test_users(findById: fn() => [
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $hashBefore = (string) \App\Models\User::findById(self::USER_ID)['password_hash'];
        $_POST = ['current_password' => $this->wrongPassword, 'new_password' => $this->newPassword, 'confirm_password' => $this->newPassword];
        $this->controller->saveProfilePassword(Request::capture());
        $this->assertSame($hashBefore, (string) \App\Models\User::findById(self::USER_ID)['password_hash'], 'mot de passe inchangé');
    }

    public function testPasswordChangeAcceptedWithTheRightCurrentPassword(): void
    {
        kintai_test_users(findById: fn() => [
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $_POST = ['current_password' => $this->rightPassword, 'new_password' => $this->newPassword, 'confirm_password' => $this->newPassword];
        $this->controller->saveProfilePassword(Request::capture());
        $this->assertTrue(password_verify($this->newPassword, (string) \App\Models\User::findById(self::USER_ID)['password_hash']));
    }

    private function changePasswordTo(string $new): \Symfony\Component\HttpFoundation\Response
    {
        kintai_test_users(findById: fn() => [
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $_POST = ['current_password' => $this->rightPassword, 'new_password' => $new, 'confirm_password' => $new];

        return $this->controller->saveProfilePassword(Request::capture());
    }

    private function locationOf(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return kintai_test_headers($response)['Location'] ?? '';
    }

    public function testANewPasswordShorterThanThePolicyIsRefused(): void
    {
        // 7 caractères : un de moins que le minimum, le même que celui exigé à la réinitialisation par e-mail.
        $location = $this->locationOf($this->changePasswordTo(substr(bin2hex(random_bytes(8)), 0, PasswordPolicy::MIN_LENGTH - 1)));

        $this->assertStringContainsString('password_too_short', (string) session('error'));
    }

    public function testFourCharactersNoLongerPass(): void
    {
        // Ancien seuil du profil (4) : c'est précisément l'incohérence corrigée.
        $location = $this->locationOf($this->changePasswordTo(substr(bin2hex(random_bytes(4)), 0, 4)));

        $this->assertStringContainsString('password_too_short', (string) session('error'));
    }

    public function testAPasswordOfExactlyTheMinimumLengthIsAccepted(): void
    {
        $location = $this->locationOf($this->changePasswordTo(substr(bin2hex(random_bytes(8)), 0, PasswordPolicy::MIN_LENGTH)));

        $this->assertNull(session('error'));
    }

    public function testAccountDeletionRefusedWhenStoredHashIsEmpty(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::USER_ID, 'password_hash' => '']);
        $_POST = ['password' => $this->wrongPassword];
        $this->controller->deleteAccount(Request::capture());
        $this->assertNull(\App\Models\User::findById(self::USER_ID)['deleted_at'], 'compte conservé');
    }

    public function testAccountDeletionRefusedWhenPasswordIsWrong(): void
    {
        kintai_test_users(findById: fn() => [
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $_POST = ['password' => $this->wrongPassword];
        $this->controller->deleteAccount(Request::capture());
        $this->assertNull(\App\Models\User::findById(self::USER_ID)['deleted_at'], 'compte conservé');
    }
}
