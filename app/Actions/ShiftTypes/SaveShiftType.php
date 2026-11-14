<?php

namespace App\Actions\ShiftTypes;

use App\Authorization\Authorizer;
use App\Models\ShiftType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftTypeCreated;
use Kintai\Contracts\Events\ShiftTypeUpdated;

/**
 * Crée ou modifie un type de shift et ses magasins.
 */
class SaveShiftType
{
    use AuthorizesShiftTypes;

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, ?ShiftType $type, array $input): ShiftType
    {
        if ($type === null) {
            if (! $this->authorizer->allows($actor, 'shifts.update')) {
                throw new AuthorizationException;
            }
        } else {
            $this->authorizeManageType($actor, $type);
        }

        $allowed = $this->authorizer->storeIdsFor($actor, 'shifts.update');

        $data = Validator::make($this->normalize($input), [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,20}$/'],
            'name' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date_format:H:i'],
            // Une fin avant le début est un passage à minuit ; seule l'égalité est refusée.
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => array_filter([
                'integer',
                Rule::exists('stores', 'id')->whereNull('deleted_at'),
                $allowed === null ? null : Rule::in($allowed),
            ]),
        ])->validate();

        $storeIds = array_values(array_unique(array_map('intval', $data['store_ids'])));
        unset($data['store_ids']);

        $saved = DB::transaction(function () use ($type, $data, $storeIds, $allowed): ShiftType {
            $saved = $type ?? new ShiftType;
            $saved->fill($data)->save();

            // Les rattachements que l'acteur ne gère pas (magasin supprimé) sont conservés.
            $kept = $allowed === null ? [] : array_values(array_diff(
                $saved->stores()->withTrashed()->pluck('stores.id')->map(fn ($id): int => (int) $id)->all(),
                $allowed,
            ));
            $saved->stores()->sync(array_merge($storeIds, $kept));

            return $saved;
        });

        if ($type === null) {
            ShiftTypeCreated::dispatch($saved->id);
        } else {
            ShiftTypeUpdated::dispatch($saved->id);
        }

        return $saved;
    }

    /**
     * Valeurs par défaut du formulaire et normalisation du code.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }

        if (is_string($input['code'] ?? null)) {
            $input['code'] = Str::upper($input['code']);
        }

        foreach (['starts_at' => '08:00', 'ends_at' => '16:00', 'hourly_rate' => '0', 'color' => '#3B82F6', 'sort_order' => '0'] as $field => $default) {
            if (($input[$field] ?? '') === '') {
                $input[$field] = $default;
            }
        }

        $input['is_active'] ??= true;
        $input['store_ids'] = is_array($input['store_ids'] ?? null) ? $input['store_ids'] : [];

        return $input;
    }
}
