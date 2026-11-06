<?php

namespace App\Actions\Employees;

use App\Authorization\Authorizer;
use App\Authorization\OwnerGuard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Kintai\Contracts\Employees\AccountActivation;
use Kintai\Contracts\Events\EmployeeUpdated;

/**
 * Désactivation et réactivation d'un compte pour les bundles (contrat `AccountActivation`) : mêmes garde-fous que la
 * fiche employé (pas soi-même sauf Owner, jamais le dernier Owner actif). La révocation des sessions et des jetons est
 * faite par le modèle `User` quand `is_active` passe à faux.
 */
class SetAccountActivation implements AccountActivation
{
    use AuthorizesEmployeeActions;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OwnerGuard $ownerGuard,
    ) {}

    public function deactivate(int $userId, ?int $actorId = null): bool
    {
        $target = User::query()->find($userId);

        if ($target === null || ! $target->is_active) {
            return false;
        }

        if ($actorId === null) {
            $this->assertNotLastOwner($target);
        } else {
            $actor = User::query()->findOrFail($actorId);

            $this->authorizeOnEmployee($actor, 'employees.update', $target);
            $this->ownerGuard->assertNotSelfUnlessOwner($actor, $target);
            $this->ownerGuard->assertCanRemoveOwner($actor, $target);
        }

        return $this->change($target, false);
    }

    public function reactivate(int $userId): bool
    {
        $target = User::query()->find($userId);

        if ($target === null || $target->is_active) {
            return false;
        }

        return $this->change($target, true);
    }

    /**
     * Sans acteur, seule la règle du dernier Owner s'applique.
     *
     * @throws AuthorizationException
     */
    private function assertNotLastOwner(User $target): void
    {
        if ($this->authorizer->isOwner($target) && array_diff($this->authorizer->activeOwnerIds(), [$target->id]) === []) {
            throw new AuthorizationException(__('The last active Owner cannot be removed.'));
        }
    }

    private function change(User $target, bool $active): bool
    {
        $target->forceFill(['is_active' => $active])->save();
        $this->authorizer->forget($target);
        EmployeeUpdated::dispatch($target->id);

        return true;
    }
}
