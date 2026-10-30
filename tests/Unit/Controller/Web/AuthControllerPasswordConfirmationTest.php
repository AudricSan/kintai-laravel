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
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
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

    private UserRepositoryInterface&MockObject $users;
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

        $this->users = $this->createMock(UserRepositoryInterface::class);
        kintai_test_assignments(findByUser: fn() => []);
        $auth = new AuthService(
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        );

        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
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
        $this->users->method('findById')->willReturn(['id' => self::USER_ID, 'password_hash' => '']);
        $this->users->expects($this->never())->method('save');

        $_POST = ['current_password' => $this->wrongPassword, 'new_password' => $this->newPassword, 'confirm_password' => $this->newPassword];
        $this->controller->saveProfilePassword(Request::capture());
    }

    public function testPasswordChangeRefusedWhenCurrentPasswordIsWrong(): void
    {
        $this->users->method('findById')->willReturn([
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->users->expects($this->never())->method('save');

        $_POST = ['current_password' => $this->wrongPassword, 'new_password' => $this->newPassword, 'confirm_password' => $this->newPassword];
        $this->controller->saveProfilePassword(Request::capture());
    }

    public function testPasswordChangeAcceptedWithTheRightCurrentPassword(): void
    {
        $this->users->method('findById')->willReturn([
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->users->expects($this->once())->method('save')->with($this->callback(
            fn(array $u): bool => password_verify($this->newPassword, (string) $u['password_hash']),
        ));

        $_POST = ['current_password' => $this->rightPassword, 'new_password' => $this->newPassword, 'confirm_password' => $this->newPassword];
        $this->controller->saveProfilePassword(Request::capture());
    }

    private function changePasswordTo(string $new): \Symfony\Component\HttpFoundation\Response
    {
        $this->users->method('findById')->willReturn([
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
        $this->users->expects($this->never())->method('save');

        $location = $this->locationOf($this->changePasswordTo(substr(bin2hex(random_bytes(8)), 0, PasswordPolicy::MIN_LENGTH - 1)));

        $this->assertStringContainsString('password_too_short', (string) session('error'));
    }

    public function testFourCharactersNoLongerPass(): void
    {
        // Ancien seuil du profil (4) : c'est précisément l'incohérence corrigée.
        $this->users->expects($this->never())->method('save');

        $location = $this->locationOf($this->changePasswordTo(substr(bin2hex(random_bytes(4)), 0, 4)));

        $this->assertStringContainsString('password_too_short', (string) session('error'));
    }

    public function testAPasswordOfExactlyTheMinimumLengthIsAccepted(): void
    {
        $this->users->expects($this->once())->method('save');

        $location = $this->locationOf($this->changePasswordTo(substr(bin2hex(random_bytes(8)), 0, PasswordPolicy::MIN_LENGTH)));

        $this->assertNull(session('error'));
    }

    public function testAccountDeletionRefusedWhenStoredHashIsEmpty(): void
    {
        $this->users->method('findById')->willReturn(['id' => self::USER_ID, 'password_hash' => '']);
        $this->users->expects($this->never())->method('save');

        $_POST = ['password' => $this->wrongPassword];
        $this->controller->deleteAccount(Request::capture());
    }

    public function testAccountDeletionRefusedWhenPasswordIsWrong(): void
    {
        $this->users->method('findById')->willReturn([
            'id' => self::USER_ID, 'password_hash' => password_hash($this->rightPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->users->expects($this->never())->method('save');

        $_POST = ['password' => $this->wrongPassword];
        $this->controller->deleteAccount(Request::capture());
    }
}
