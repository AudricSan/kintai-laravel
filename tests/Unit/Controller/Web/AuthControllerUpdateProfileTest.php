<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
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

    /** Choix vide (« Langue de l'instance ») ou langue désactivée : le compte suit la langue de l'instance (''). */
    public function testAnEmptyOrInactiveLanguageMeansFollowingTheInstance(): void
    {
        $userId = 43;
        session()->put(auth()->getName(), $userId);

        $saved = [];
        kintai_test_users(findById: fn() => ['id' => $userId, 'language' => 'fr']);
        kintai_test_assignments(findByUser: fn() => []);
        $auth = new AuthService();

        kintai_test_languages(['fr', 'en']);

        $controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()), $auth, new AuditLogger(), new AvatarImageOptimizer(),
        );

        foreach (['', 'de', 'en'] as $choice) {
            $_POST = ['language' => $choice];
            $controller->updateProfile(Request::capture());
            $saved[] = \App\Models\User::findById($userId)['language'];
        }

        $this->assertSame(['', '', 'en'], $saved);
    }
}
