<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Models\ApiToken;
use App\Models\Availability;
use App\Models\IcalToken;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Changer son mot de passe depuis le profil : la session courante reste valide, mais toutes les
 * autres sessions, les cookies « rester connecté » et les jetons d'API sont révoqués.
 * Supprimer son compte purge aussi ces identifiants persistants.
 */
final class AuthControllerCredentialRevocationTest extends TestCase
{
    use \Tests\Support\ApiTokenExpectations;

    private const USER_ID = 7;
    private AuthService $auth;
    private AuthController $controller;
    private string $currentPassword;
    /** @var array<string, mixed> */
    private array $row;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        session()->put(auth()->getName(), self::USER_ID);

        $this->currentPassword = bin2hex(random_bytes(8));
        $this->row = [
            'id'            => self::USER_ID,
            'email'         => 'emp@example.test',
            'is_active'     => 1,
            'deleted_at'    => null,
            'password_hash' => password_hash($this->currentPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ];
        kintai_test_users(findById: fn() => $this->row);
        // Le dépôt persiste ce que le contrôleur sauvegarde, comme la vraie base.
        kintai_test_assignments(findByUser: fn() => []);

        $this->auth = new AuthService(
        );

        $this->buildController();
    }

    /**
     * Contrôleur branché sur les dépôts de jetons courants : un test qui vérifie la révocation remplace d'abord les
     * bouchons par des mocks (useTokenMocks()).
     */
    private function buildController(): void
    {
        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $this->auth,
            new AuditLogger(),
            new AvatarImageOptimizer(),
            new CredentialRevoker(),
            new UserAnonymizer(new CredentialRevoker(), sys_get_temp_dir()),
        );
    }

    private function useTokenMocks(): void
    {
        $this->buildController();
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    private function changePassword(string $current, string $new): void
    {
        $_POST = ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $new];
        $this->controller->saveProfilePassword(Request::capture());
    }

    public function testPasswordChangeRevokesRememberCookiesAndApiTokens(): void
    {
        $this->useTokenMocks();
        kintai_test_remember_token(self::USER_ID);
        $this->expectApiTokensRevoked(self::USER_ID);

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));
        $this->assertNull(\App\Models\User::query()->find(self::USER_ID)?->remember_token, 'jeton « rester connecté » révoqué');
    }

    public function testCurrentSessionSurvivesItsOwnPasswordChange(): void
    {
        // La session est « liée » à l'ancien mot de passe (comme après une connexion).
        $this->assertTrue($this->auth->check());

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));

        $this->assertTrue($this->auth->check());
    }

    public function testWrongCurrentPasswordRevokesNothing(): void
    {
        $this->useTokenMocks();
        $this->expectApiTokensKept(1);

        $this->changePassword(bin2hex(random_bytes(8)), bin2hex(random_bytes(8)));
    }

    public function testAccountDeletionPurgesRememberCookiesAndApiTokens(): void
    {
        $this->useTokenMocks();
        kintai_test_remember_token(self::USER_ID);
        $this->expectApiTokensRevoked(self::USER_ID);

        $_POST = ['password' => $this->currentPassword];
        $this->controller->deleteAccount(Request::capture());
        $this->assertNull(\App\Models\User::query()->find(self::USER_ID)?->remember_token, 'jeton « rester connecté » révoqué');
    }
}
