<?php

namespace App\Actions\Api;

use App\Actions\Fortify\AuthenticateUser;
use App\Activity\ActivityLogger;
use App\Exceptions\ApiException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\NewAccessToken;

/**
 * Connexion à l'API : mêmes identifiants et mêmes règles que la connexion web (`AuthenticateUser` : e-mail, ou code employé
 * et code magasin ; même message et même durée pour tous les refus), puis second facteur si le compte en a un, puis un
 * jeton d'accès à durée limitée. Aucune session n'est ouverte.
 */
class LoginApiUser
{
    public function __construct(
        private readonly AuthenticateUser $authenticate,
        private readonly TwoFactorAuthenticationProvider $twoFactor,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array{token: NewAccessToken, user: User, expires_at: CarbonImmutable}
     *
     * @throws ApiException 401 `invalid_credentials`, `two_factor_required`, `invalid_two_factor_code` ; 403 `password_change_required`
     */
    public function handle(Request $request, string $deviceName, int $days): array
    {
        $user = ($this->authenticate)($request);

        if ($user === null) {
            // Journalisé (`auth.login_failed`) par l'écouteur d'activité, comme sur le web : seul l'identifiant saisi est gardé.
            event(new Failed('sanctum', null, ['login' => $request->input('login')]));

            throw new ApiException(401, 'invalid_credentials', __('The credentials do not match any account.'));
        }

        // Un jeton est un accès durable sans écran de rappel : un mot de passe trop court doit d'abord être changé sur le site.
        if (mb_strlen((string) $request->input('password')) < 8) {
            throw new ApiException(403, 'password_change_required', __('Change your password on the website (8 characters minimum) before using the API.'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->verifySecondFactor($request, $user);
        }

        $expiresAt = CarbonImmutable::now()->addDays($days);
        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        $this->activity->record('auth.api_login', $user, null, ['name' => $user->name, 'device' => $deviceName], $user);

        return ['token' => $token, 'user' => $user, 'expires_at' => $expiresAt];
    }

    /**
     * Code à usage unique de l'application, ou code de récupération (consommé).
     */
    private function verifySecondFactor(Request $request, User $user): void
    {
        $code = trim((string) $request->input('code'));
        $recoveryCode = trim((string) $request->input('recovery_code'));

        if ($code === '' && $recoveryCode === '') {
            throw new ApiException(401, 'two_factor_required', __('A two-factor authentication code is required.'));
        }

        if ($recoveryCode !== '') {
            $match = collect($user->recoveryCodes())->first(fn (string $stored): bool => hash_equals($stored, $recoveryCode));

            if ($match !== null) {
                $user->replaceRecoveryCode($match);

                return;
            }
        } elseif ($this->twoFactor->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code)) {
            return;
        }

        event(new Failed('sanctum', $user, ['login' => $request->input('login')]));

        throw new ApiException(401, 'invalid_two_factor_code', __('The two-factor authentication code is invalid.'));
    }
}
