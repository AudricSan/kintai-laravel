<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use kintai\Contract\Extension\ProfileSection;
use App\Auth\AuthService;
use App\Bundles\Bundle;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Extension\ExtensionRegistry;
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

        (new \ReflectionProperty(Container::class, 'instance'))->setValue(null, new \Tests\Support\TestContainer());
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    protected function setUp(): void
    {
        kintai_test_database(); // avant de remplacer le conteneur : les migrations en ont besoin
        kintai_test_migrate();
        kintai_test_fk_off();
        $this->previousContainer = (new \ReflectionProperty(Container::class, 'instance'))->getValue();
        $this->activeBundles('team-directory');


        $userId = 7;
        session()->put(auth()->getName(), $userId);
        kintai_test_users(findById: fn() => [
            'id' => $userId, 'language' => 'fr', 'show_in_directory' => 1,
        ]);
        kintai_test_assignments(findByUser: fn() => []);

        $auth = new AuthService();

        kintai_test_languages(['fr']);

        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
        (new \ReflectionProperty(Container::class, 'instance'))->setValue(null, $this->previousContainer);
    }

    /** team-directory désactivé : ses cases ne sont ni affichées ni enregistrées, elles gardent leur valeur. */
    public function testDirectoryFlagsAreLeftUntouchedWhenTeamDirectoryIsDisabled(): void
    {
        $this->activeBundles();
        $_POST = ['language' => 'fr', 'show_in_directory' => '0', 'share_email' => '1'];

        $saved = null;
        $this->controller->updateProfile(Request::capture());

        $saved = \App\Models\User::query()->orderByDesc('id')->first()?->toArray();
        $this->assertSame(1, $saved['show_in_directory']);
        $this->assertEmpty($saved['share_email'], 'case absente : valeur conservée (désactivée)');
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
        $this->controller->updateProfile(Request::capture());

        $saved = \App\Models\User::query()->orderByDesc('id')->first()?->toArray();
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
        $this->controller->updateProfile(Request::capture());

        $saved = \App\Models\User::query()->orderByDesc('id')->first()?->toArray();
        $this->assertSame(0, $saved['show_in_directory']);
    }

    public function testShareContactFlagsDefaultToDisabledWhenCheckboxesUnchecked(): void
    {
        $_POST = ['language' => 'fr'];

        $saved = null;
        $this->controller->updateProfile(Request::capture());

        $saved = \App\Models\User::query()->orderByDesc('id')->first()?->toArray();
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
        $this->controller->updateProfile(Request::capture());

        $saved = \App\Models\User::query()->orderByDesc('id')->first()?->toArray();
        $this->assertSame('Bonjour', $saved['bio']);
        $this->assertSame(1, $saved['show_in_directory'], 'case absente : valeur conservée');
        $this->assertEmpty($saved['phone'], 'champ absent : non réécrit (resté vide)');
        $this->assertSame(0, $saved['share_email']);
    }
}
