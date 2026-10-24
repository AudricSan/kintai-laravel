<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvatarImageOptimizer;
use kintai\UI\Controller\Web\AuthController;
use kintai\UI\ViewRenderer;
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
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
        $_POST = [];
    }

    public function testPropagatesSaveFailureInsteadOfRedirectingToFalseSuccess(): void
    {
        $userId = 42;
        $_SESSION['auth_user_id'] = $userId;

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with($userId)->willReturn(['id' => $userId, 'language' => 'fr']);
        $users->method('save')->willThrowException(new \RuntimeException('DB write failed'));

        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roles           = $this->createStub(RoleRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);

        $rememberTokens = $this->createStub(RememberTokenRepositoryInterface::class);

        $auth = new AuthService($users, $storeUsers, $stores, $roles, $roleAssignments, $rememberTokens);

        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);
        $languages->method('findDefault')->willReturn(['code' => 'fr']);

        $controller = new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $stores,
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $languages,
            new AvatarImageOptimizer(),
        );

        $_POST = ['language' => 'en'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB write failed');
        $controller->updateProfile(new Request());
    }

    /** Choix vide (« Langue de l'instance ») ou langue désactivée : le compte suit la langue de l'instance (''). */
    public function testAnEmptyOrInactiveLanguageMeansFollowingTheInstance(): void
    {
        $userId = 43;
        $_SESSION['auth_user_id'] = $userId;

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
        $auth = new AuthService($users, $storeUsers, $stores, $this->createStub(RoleRepositoryInterface::class), $roleAssignments, $this->createStub(RememberTokenRepositoryInterface::class));

        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);

        $controller = new AuthController(
            new ViewRenderer(sys_get_temp_dir()), $auth, new AuditLogger(), $users, $stores, $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class), $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class), $languages, new AvatarImageOptimizer(),
        );

        foreach (['', 'de', 'en'] as $choice) {
            $_POST = ['language' => $choice];
            $controller->updateProfile(new Request());
        }

        $this->assertSame(['', '', 'en'], $saved);
    }
}
