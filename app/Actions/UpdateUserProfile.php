<?php

namespace App\Actions;

use App\Events\ProfileUpdated;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Enregistre ce que l'employé gère lui-même : langue, choix de partage et coordonnées personnelles.
 * Identité (prénom, nom, e-mail, code) reste au gérant.
 */
class UpdateUserProfile
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input): void
    {
        $input['locale'] = ($input['locale'] ?? '') === '' ? null : $input['locale'];

        $validated = Validator::make($input, [
            'locale' => ['nullable', Rule::in(array_keys(Locales::SUPPORTED))],
            'share_email' => ['boolean'],
            'share_phone' => ['boolean'],
            'share_mobile_phone' => ['boolean'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile_phone' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
        ])->validate();

        DB::transaction(function () use ($user, $validated): void {
            $user->fill([
                'locale' => $validated['locale'] ?? null,
                'share_email' => (bool) ($validated['share_email'] ?? false),
                'share_phone' => (bool) ($validated['share_phone'] ?? false),
                'share_mobile_phone' => (bool) ($validated['share_mobile_phone'] ?? false),
            ])->save();

            $user->profile()->updateOrCreate([], [
                'phone' => $this->blankToNull($validated['phone'] ?? null),
                'mobile_phone' => $this->blankToNull($validated['mobile_phone'] ?? null),
                'postal_code' => $this->blankToNull($validated['postal_code'] ?? null),
                'address' => $this->blankToNull($validated['address'] ?? null),
            ]);
        });

        ProfileUpdated::dispatch($user->id);
    }

    private function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
