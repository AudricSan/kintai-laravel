<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
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
 * updateProfile() enveloppait la sauvegarde de la langue dans un catch(\Throwable) muet
 * ("colonne language absente, migration non exécutée — session suffit"), puis redirigeait
 * quand même vers ?success=1. Cette justification n'est plus valable : la colonne "language"
 * est définie dans la toute première migration de création de la table users, elle ne peut
 * pas manquer si la table existe. Le catch masquait donc n'importe quelle vraie panne DB
 * derrière un succès trompeur. Ce test vérifie que l'échec remonte désormais normalement
 * (géré ensuite par Application::handleException(), déjà testé séparément).
 */
final class AuthControllerUpdateProfileTest extends TestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    public function testPropagatesSaveFailureInsteadOfRedirectingToFalseSuccess(): void
    {
        $userId = 42;
        session()->put(auth()->getName(), $userId);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with($userId)->willReturn(['id' => $userId, 'language' => 'fr']);
        $users->method('save')->willThrowException(new \RuntimeException('DB write failed'));

        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);

        $rememberTokens = $this->createStub(UserRepositoryInterface::class);

        $auth = new AuthService($users, $storeUsers, $stores, $roleAssignments, $rememberTokens);

        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);
        $languages->method('findDefault')->willReturn(['code' => 'fr']);

        $controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $stores,
            $storeUsers,
            $languages,
            new AvatarImageOptimizer(),
        );

        $_POST = ['language' => 'en'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB write failed');
        $controller->updateProfile(Request::capture());
    }

    /** Choix vide (« Langue de l'instance ») ou langue désactivée : le compte suit la langue de l'instance (''). */
    public function testAnEmptyOrInactiveLanguageMeansFollowingTheInstance(): void
    {
        $userId = 43;
        session()->put(auth()->getName(), $userId);

        $saved = [];
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with($userId)->willReturn(['id' => $userId, 'language' => 'fr']);
        $users->method('save')->willReturnCallback(function (array $u) use (&$saved): array {
            $saved[] = $u['language'];
            return $u;
        });

        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $auth = new AuthService($users, $storeUsers, $stores, $roleAssignments);

        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);

        $controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()), $auth, new AuditLogger(), $users, $stores, $storeUsers, $languages, new AvatarImageOptimizer(),
        );

        foreach (['', 'de', 'en'] as $choice) {
            $_POST = ['language' => $choice];
            $controller->updateProfile(Request::capture());
        }

        $this->assertSame(['', '', 'en'], $saved);
    }
}
