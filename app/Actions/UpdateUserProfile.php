<?php

namespace App\Actions;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class UpdateUserProfile
{
    use ProfileValidationRules;

    /**
     * Valide puis enregistre le nom et l'e-mail de l'utilisateur.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input): void
    {
        $validated = Validator::make($input, $this->profileRules($user->id))->validate();

        $user->fill($validated)->save();
    }
}
