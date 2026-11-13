<?php

namespace App\Actions\Employees;

use App\Actions\RevokeUserSessions;
use App\Authorization\PasswordDefaults;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\EmployeePasswordReset;

/**
 * Réinitialise (mot de passe par défaut) ou redéfinit le mot de passe d'un employé, et révoque ses sessions.
 */
class ResetEmployeePassword
{
    use AuthorizesEmployeeActions;

    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    /**
     * @param  string|null  $password  null = mot de passe par défaut, avec changement exigé
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, ?string $password = null, bool $mustChange = true): void
    {
        $this->authorizeOnEmployee($actor, 'employees.update', $target);
        $this->authorizeTakeOver($actor, 'employees.update', $target);

        if ($password === null) {
            $password = PasswordDefaults::DEFAULT_PASSWORD;
            $mustChange = true;
        } else {
            Validator::make(['password' => $password], ['password' => ['required', 'string', Password::defaults()]])->validate();
        }

        DB::transaction(function () use ($target, $password, $mustChange): void {
            // Le hook de User lève le drapeau à tout changement de mot de passe : on le repose dans un second enregistrement,
            // car il peut déjà être à true (sans changement détecté dans le même enregistrement).
            $target->forceFill(['password' => $password])->save();

            if ($mustChange) {
                $target->forceFill(['must_change_password' => true])->save();
            }

            $this->revokeSessions->handle($target);
        });

        EmployeePasswordReset::dispatch($target->id);
    }
}
