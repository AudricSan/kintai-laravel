<?php

namespace App\Actions\Employees;

use App\Actions\AnonymizeUser;
use App\Authorization\OwnerGuard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Supprime un employé = l'anonymise (l'historique reste rattaché).
 */
class DeleteEmployee
{
    use AuthorizesEmployeeActions;

    public function __construct(
        private readonly OwnerGuard $ownerGuard,
        private readonly AnonymizeUser $anonymizeUser,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target): void
    {
        $this->authorizeOnEmployee($actor, 'employees.delete', $target);

        try {
            $this->ownerGuard->assertNotSelfUnlessOwner($actor, $target);
            $this->ownerGuard->assertCanRemoveOwner($actor, $target);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }

        $this->anonymizeUser->handle($target);
    }
}
