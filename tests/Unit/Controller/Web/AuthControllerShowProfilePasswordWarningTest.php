<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * showProfile() calcule désormais has_default_password (password_verify du
 * hash stocké contre le mot de passe par défaut "0000") pour que la vue
 * affiche un avertissement invitant l'utilisateur à le changer.
 */
final class AuthControllerShowProfilePasswordWarningTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    private const USER_ID = 7;

    private $users;
    private string $viewDir;
    private AuthController $controller;

    protected function setUp(): void
    {
        session()->put(auth()->getName(), self::USER_ID);

        $this->viewDir = sys_get_temp_dir() . '/kintai-auth-profile-test';
        $this->ensureViewFile($this->viewDir, 'auth.profile', "<?= !empty(\$has_default_password) ? 'DEFAULT_PW_WARNING' : 'NO_WARNING' ?>");
        $this->ensureViewFile($this->viewDir, 'layout.app', '<?= $content ?>');

        $this->users = $this->createMock(UserRepositoryInterface::class);

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByUser')->willReturn([]);
        kintai_test_assignments(findByUser: fn() => []);
        $auth = new AuthService(
            $this->users,
            $storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        );

        $this->controller = new AuthController(
            kintai_test_views($this->viewDir),
            $auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $storeUsers,
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
    }

    public function testWarningShownWhenPasswordIsStillTheDefault(): void
    {
        $this->users->method('findById')->with(self::USER_ID)->willReturn([
            'id'            => self::USER_ID,
            'password_hash' => password_hash('0000', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $response = $this->controller->showProfile(Request::capture());

        $this->assertStringContainsString('DEFAULT_PW_WARNING', $response->getContent());
    }

    public function testWarningHiddenWhenPasswordHasBeenChanged(): void
    {
        $this->users->method('findById')->with(self::USER_ID)->willReturn([
            'id'            => self::USER_ID,
            'password_hash' => password_hash('a-real-password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $response = $this->controller->showProfile(Request::capture());

        $this->assertStringContainsString('NO_WARNING', $response->getContent());
    }

    private function ensureViewFile(string $dir, string $view, string $content): void
    {
        $file   = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($file, $content);
    }
}
