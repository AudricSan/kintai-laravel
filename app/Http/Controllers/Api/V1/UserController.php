<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\DeleteEmployee;
use App\Actions\Employees\ResetEmployeePassword;
use App\Actions\Employees\UpdateEmployee;
use App\Authorization\PasswordDefaults;
use App\Http\Resources\V1\EmployeeResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Employés. Toute la logique (droits, plafond de rôle, Owner protégé, unicité) est celle des actions de l'interface web.
 */
class UserController extends ApiController
{
    /** Champs RH enregistrés avec l'identité (voir UpdateEmployee). */
    private const PROFILE_FIELDS = ['gender', 'birth_date', 'address', 'postal_code', 'phone', 'mobile_phone', 'tax_classification', 'education', 'guarantor_name', 'guarantor_phone'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, 'employees.view')) {
            throw new AuthorizationException;
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'store_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $ids = $this->authorizer()->storeIdsFor($actor, 'employees.view');
        $query = User::query()->with('stores');

        // Sans restriction : tous les comptes ; sinon, les membres des magasins de l'acteur.
        if ($ids !== null) {
            $query->whereHas('stores', fn (Builder $q) => $q->whereIn('stores.id', $ids));
        }

        if (isset($filters['store_id'])) {
            $storeId = (int) $filters['store_id'];
            $query->whereHas('stores', fn (Builder $q) => $q->where('stores.id', $ids === null || in_array($storeId, $ids, true) ? $storeId : 0));
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $q) use ($like): void {
                foreach (['name', 'first_name', 'last_name', 'furigana_first_name', 'furigana_last_name', 'email', 'employee_code'] as $column) {
                    $q->orWhereRaw("{$column} like ? escape '!'", [$like]);
                }
            });
        }

        return EmployeeResource::collection($query->orderBy('name')->orderBy('id')->paginate($this->perPage($request)));
    }

    public function show(Request $request, int $user): EmployeeResource
    {
        $target = $this->targetUser($request, $user, 'employees.view');

        return $this->resource($target);
    }

    public function store(Request $request, CreateEmployee $create): JsonResponse
    {
        $input = $request->only(['first_name', 'last_name', 'furigana_first_name', 'furigana_last_name', 'name', 'email', 'employee_code', 'color', 'password', 'store_id', 'role_id', 'is_owner']);
        $user = $create->handle($this->actor($request), $input, 'api');

        $response = $this->resource($user->refresh())->response()->setStatusCode(201);

        // Le mot de passe provisoire n'est donné que lorsque le compte n'a pas reçu de mot de passe choisi (comme l'écran web).
        if (! isset($input['password']) || $input['password'] === '') {
            $response->setData(['data' => $response->getData(true)['data'] + ['temporary_password' => PasswordDefaults::DEFAULT_PASSWORD]]);
        }

        return $response;
    }

    public function update(Request $request, int $user, UpdateEmployee $update): EmployeeResource
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $target->load('profile');

        // PUT partiel toléré : une clé absente garde la valeur actuelle (les actions exigent l'enregistrement complet).
        $current = [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'furigana_first_name' => $target->furigana_first_name,
            'furigana_last_name' => $target->furigana_last_name,
            'email' => $target->email,
            'employee_code' => $target->employee_code,
            'color' => $target->color,
            'is_active' => $target->is_active,
            'is_owner' => $this->authorizer()->isOwner($target),
        ];
        foreach (self::PROFILE_FIELDS as $field) {
            $value = $target->profile?->{$field};
            $current[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        if (! $request->has('first_name') && ! $request->has('last_name')) {
            $current['name'] = $target->name;
        }

        $input = $request->only(array_merge(array_keys($current), ['name', 'store_roles'])) + $current;
        $update->handle($this->actor($request), $target, $input);

        return $this->resource($target->refresh());
    }

    public function destroy(Request $request, int $user, DeleteEmployee $delete): Response
    {
        $target = $this->targetUser($request, $user, 'employees.delete');

        $delete->handle($this->actor($request), $target);

        return response()->noContent();
    }

    /**
     * Sans `password` : mot de passe provisoire (changement exigé), renvoyé une seule fois. Avec `password` : mot de passe
     * choisi (`must_change`, vrai par défaut), rien n'est renvoyé. Les jetons de l'employé sont révoqués.
     */
    public function resetPassword(Request $request, int $user, ResetEmployeePassword $reset): JsonResponse|Response
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $data = $request->validate(['password' => ['nullable', 'string'], 'must_change' => ['nullable', 'boolean']]);
        $password = ($data['password'] ?? '') === '' ? null : $data['password'];

        $reset->handle($this->actor($request), $target, $password, (bool) ($data['must_change'] ?? true));

        if ($password !== null) {
            return response()->noContent();
        }

        return response()->json(['data' => ['temporary_password' => PasswordDefaults::DEFAULT_PASSWORD, 'must_change_password' => true]]);
    }

    private function resource(User $user): EmployeeResource
    {
        return new EmployeeResource($user->load(['stores', 'roles', 'profile']));
    }
}
