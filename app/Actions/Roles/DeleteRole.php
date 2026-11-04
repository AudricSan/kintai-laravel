<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Role $role): void
    {
        if (! Gate::forUser($actor)->allows('manage-roles')) {
            throw new AuthorizationException;
        }

        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('A system role cannot be deleted.')]);
        }

        if ($role->assignments()->exists()) {
            throw ValidationException::withMessages(['role' => __('This role still has holders and cannot be deleted.')]);
        }

        $role->delete();
    }
}
