<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Contract\Extension\ProfileSection;
use kintai\Core\Auth\AuthService;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
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
 * updateProfile() ne gérait jusqu'ici que la langue — il porte désormais aussi
 * les champs de coordonnées en auto-édition (phone/mobile_phone/postal_code/
 * address) et le profil enrichi du bundle TeamDirectory (bio/skills/
 * languages_spoken/hobbies/show_in_directory). Ce test vérifie que toutes ces
 * valeurs sont bien transmises telles quelles au repository.
 *
 * Les cases de l'annuaire passent par les sections de profil des bundles (contrat 1.12) : le setUp active un
 * team-directory qui déclare sa section, comme sur une instance où l'annuaire est en service.
 */
final class AuthControllerProfileFieldsTest extends TestCase
{
    private AuthController $controller;
    private $users;
    private ?Container $previousContainer = null;

    /** Bundles actifs de la requête (sections de profil comprises), par slug. */
    private function activeBundles(string ...$slugs): void
    {
        $bundles = [];
        foreach ($slugs as $slug) {
            $bundle = new class extends Bundle {
                public string $slug = '';

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function profileSections(): array
                {
                    return $this->slug === 'team-directory'
                        ? [new ProfileSection('directory', 'team-directory::profile-section', ['show_in_directory', 'share_email', 'share_phone', 'share_mobile_phone'])]
                        : [];
                }
            };
            $bundle->slug = $slug;
            $bundles[] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        (new \ReflectionProperty(Container::class, 'instance'))->setValue(null, null);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    protected function setUp(): void
    {
        $this->previousContainer = (new \ReflectionProperty(Container::class, 'instance'))->getValue();
        $this->activeBundles('team-directory');

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $userId = 7;
        $_SESSION['auth_user_id'] = $userId;

        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->with($userId)->willReturn([
            'id' => $userId, 'language' => 'fr', 'show_in_directory' => 1,
        ]);

        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roles           = $this->createStub(RoleRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $rememberTokens = $this->createStub(RememberTokenRepositoryInterface::class);

        $auth = new AuthService($this->users, $storeUsers, $stores, $roles, $roleAssignments, $rememberTokens);

        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr']]);
        $languages->method('findDefault')->willReturn(['code' => 'fr']);

        $this->controller = new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $this->users,
            $stores,
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $languages,
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
        $_POST = [];
        (new \ReflectionProperty(Container::class, 'instance'))->setValue(null, $this->previousContainer);
    }

    /** team-directory désactivé : ses cases ne sont ni affichées ni enregistrées, elles gardent leur valeur. */
    public function testDirectoryFlagsAreLeftUntouchedWhenTeamDirectoryIsDisabled(): void
    {
        $this->activeBundles();
        $_POST = ['language' => 'fr', 'show_in_directory' => '0', 'share_email' => '1'];

        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved) {
            $saved = $d;
            return $d;
        });

        $this->controller->updateProfile(new Request());

        $this->assertSame(1, $saved['show_in_directory']);
        $this->assertArrayNotHasKey('share_email', $saved);
    }

    public function testPersistsContactAndEnrichedProfileFields(): void
    {
        $_POST = [
            'language'           => 'fr',
            'phone'              => '01 23 45 67 89',
            'mobile_phone'       => '06 00 00 00 00',
            'postal_code'        => '75001',
            'address'            => '1 rue de Test',
            'bio'                => 'Bonjour, je suis un test.',
            'skills'             => 'caisse, service client',
            'languages_spoken'   => 'français, anglais',
            'hobbies'            => 'lecture',
            'show_in_directory'  => '1',
            'share_email'        => '1',
            'share_phone'        => '1',
            'share_mobile_phone' => '1',
        ];

        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved) {
            $saved = $d;
            return $d;
        });

        $this->controller->updateProfile(new Request());

        $this->assertNotNull($saved);
        $this->assertSame('01 23 45 67 89', $saved['phone']);
        $this->assertSame('06 00 00 00 00', $saved['mobile_phone']);
        $this->assertSame('75001', $saved['postal_code']);
        $this->assertSame('1 rue de Test', $saved['address']);
        $this->assertSame('Bonjour, je suis un test.', $saved['bio']);
        $this->assertSame('caisse, service client', $saved['skills']);
        $this->assertSame('français, anglais', $saved['languages_spoken']);
        $this->assertSame('lecture', $saved['hobbies']);
        $this->assertSame(1, $saved['show_in_directory']);
        $this->assertSame(1, $saved['share_email']);
        $this->assertSame(1, $saved['share_phone']);
        $this->assertSame(1, $saved['share_mobile_phone']);
    }

    public function testShowInDirectoryDefaultsToDisabledWhenCheckboxUnchecked(): void
    {
        // Case décochée : le champ caché envoie « 0 ».
        $_POST = ['language' => 'fr', 'show_in_directory' => '0'];

        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved) {
            $saved = $d;
            return $d;
        });

        $this->controller->updateProfile(new Request());

        $this->assertSame(0, $saved['show_in_directory']);
    }

    public function testShareContactFlagsDefaultToDisabledWhenCheckboxesUnchecked(): void
    {
        $_POST = ['language' => 'fr'];

        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved) {
            $saved = $d;
            return $d;
        });

        $this->controller->updateProfile(new Request());

        $this->assertSame(0, $saved['share_email']);
        $this->assertSame(0, $saved['share_phone']);
        $this->assertSame(0, $saved['share_mobile_phone']);
    }

    /**
     * Audit M12 : les cases de l'annuaire ne sont affichées que si le bundle team-directory est actif ; un
     * enregistrement du profil sans elles les remettait à 0. Une requête partielle vidait aussi les coordonnées.
     */
    public function testFieldsAbsentFromTheRequestKeepTheirValue(): void
    {
        // Compte du setUp : show_in_directory = 1, aucune coordonnée.
        $_POST = ['bio' => 'Bonjour'];

        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved) {
            $saved = $d;
            return $d;
        });

        $this->controller->updateProfile(new Request());

        $this->assertSame('Bonjour', $saved['bio']);
        $this->assertSame(1, $saved['show_in_directory'], 'case absente : valeur conservée');
        $this->assertArrayNotHasKey('phone', $saved, 'champ absent : non réécrit (pas vidé)');
        $this->assertSame(0, $saved['share_email']);
    }
}
