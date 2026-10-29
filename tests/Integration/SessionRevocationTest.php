<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AuthService;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Une session ouverte ne survit pas à un changement de mot de passe (AuthenticateSession de Laravel, dans la pile
 * globale de bootstrap/app.php), sauf celle qui a changé son propre mot de passe (refreshSessionAfterPasswordChange).
 */
final class SessionRevocationTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        $capsule = kintai_test_database();
        kintai_test_migrate();
        $now = date('Y-m-d H:i:s');
        $this->userId = (int) $capsule->table('users')->insertGetId([
            'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'email' => 'a@kintai.test',
            'password_hash' => password_hash('first-password', PASSWORD_BCRYPT, ['cost' => 4]), 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Auth::guard()->loginUsingId($this->userId);
    }

    protected function tearDown(): void
    {
        Auth::guard()->logout();
        session()->flush();
    }

    /** Une requête qui traverse AuthenticateSession avec la session courante. */
    private function request(): void
    {
        $request = Request::create('/employee');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn() => Auth::guard()->user());
        app(AuthenticateSession::class)->handle($request, static fn() => response('ok'));
    }

    private function changePassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        User::whereKey($this->userId)->update(['password_hash' => $hash]);
        Auth::guard()->forgetUser();

        return $hash;
    }

    public function testASessionIsRevokedWhenThePasswordChangesAfterItsOpening(): void
    {
        $this->request(); // la session adopte l'empreinte du mot de passe actuel
        $this->changePassword('second-password');

        $this->expectException(AuthenticationException::class);
        $this->request();
    }

    public function testTheSessionThatChangedItsOwnPasswordSurvives(): void
    {
        $this->request();
        $hash = $this->changePassword('second-password');
        app(AuthService::class)->refreshSessionAfterPasswordChange(['password_hash' => $hash]);

        $this->request();

        $this->assertSame($this->userId, Auth::guard()->id());
    }
}
