<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\DeleteShifts;
use App\Actions\Shifts\MoveShift;
use App\Actions\Shifts\ReplaceShift;
use App\Actions\Shifts\RestoreShift;
use App\Actions\Shifts\UpdateShift;
use App\Http\Resources\V1\ShiftResource;
use App\Models\Shift;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Shifts. Les droits et les règles sont ceux des actions de l'interface web (CreateShift, UpdateShift, MoveShift,
 * DeleteShift, RestoreShift, ReplaceShift). Les heures se saisissent en heures locales de l'instance (`shift_date`,
 * `start_time`, `end_time`). Les avertissements du garde-fou répondent `409 shift_warnings` tant que `force` n'est pas vrai.
 *
 * Lecture : avec `shifts.view`, les shifts des magasins visibles (et ses propres shifts) ; sans, uniquement ses propres
 * shifts (libre-service de l'employé). Un shift hors du périmètre est introuvable (404). Écriture : droits de l'action,
 * sur un shift du périmètre `shifts.view` (sans `shifts.view`, 403).
 */
class ShiftController extends ApiController
{
    private const RELATIONS = ['store', 'user', 'shiftType'];

    /** Champs de saisie d'un shift (voir ShiftPreparer). */
    private const FIELDS = ['store_id', 'user_id', 'shift_date', 'start_time', 'end_time', 'pause_minutes', 'notes', 'hourly_rate_override', 'net_minutes_override'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'open' => ['nullable', 'boolean'],
            'trashed' => ['nullable', Rule::in(['with', 'only'])],
        ]);

        $query = Shift::query()->with(self::RELATIONS);

        if ($this->authorizer()->allows($actor, 'shifts.view')) {
            $ids = $this->authorizer()->storeIdsFor($actor, 'shifts.view');

            if ($ids !== null) {
                $query->where(fn (Builder $q) => $q->whereIn('store_id', $ids)->orWhere('user_id', $actor->id));
            }

            match ($filters['trashed'] ?? null) {
                'with' => $query->withTrashed(),
                'only' => $query->onlyTrashed(),
                default => null,
            };

            if (isset($filters['user_id'])) {
                $query->where('user_id', (int) $filters['user_id']);
            }

            if (! empty($filters['open'])) {
                $query->whereNull('user_id');
            }
        } else {
            // Libre-service : ses propres shifts, jamais ceux des autres ni les shifts ouverts.
            if ((isset($filters['user_id']) && (int) $filters['user_id'] !== $actor->id) || ! empty($filters['open'])) {
                throw new AuthorizationException;
            }

            $query->where('user_id', $actor->id);
        }

        if (isset($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }
        if (isset($filters['from'])) {
            $query->where('shift_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('shift_date', '<=', $filters['to']);
        }

        return ShiftResource::collection(
            $query->orderBy('shift_date')->orderBy('starts_at')->orderBy('id')->paginate($this->perPage($request))
        );
    }

    public function show(Request $request, int $shift): ShiftResource
    {
        return new ShiftResource($this->find($request, $shift, allowOwn: true));
    }

    public function store(Request $request, CreateShift $create): JsonResponse
    {
        $created = $create->handle($this->actor($request), $request->only(self::FIELDS), $request->boolean('force'));

        return $this->respond($created)->response()->setStatusCode(201);
    }

    public function update(Request $request, int $shift, UpdateShift $update): ShiftResource
    {
        $model = $this->find($request, $shift);

        return $this->respond($update->handle($this->actor($request), $model, $request->only(self::FIELDS), $request->boolean('force')));
    }

    public function move(Request $request, int $shift, MoveShift $move): ShiftResource
    {
        $model = $this->find($request, $shift);

        return $this->respond($move->handle($this->actor($request), $model, $request->only(['shift_date', 'start_time', 'end_time', 'user_id']), $request->boolean('force')));
    }

    public function destroy(Request $request, int $shift, DeleteShift $delete): Response
    {
        $delete->handle($this->actor($request), $this->find($request, $shift));

        return response()->noContent();
    }

    public function restore(Request $request, int $shift, RestoreShift $restore): ShiftResource
    {
        return $this->respond($restore->handle($this->actor($request), $this->find($request, $shift)));
    }

    /**
     * Remplace le shift : le remplaçant est créé à partir de la saisie (les champs absents, `user_id` compris, reprennent ceux de l'ancien
     * shift ; seul un `user_id` null explicite donne un shift ouvert), l'ancien part à la corbeille.
     */
    public function replace(Request $request, int $shift, ReplaceShift $replace): JsonResponse
    {
        $old = $this->find($request, $shift);
        $defaults = [
            'store_id' => $old->store_id,
            'user_id' => $old->user_id,
            'shift_date' => $old->shift_date->toDateString(),
            'start_time' => $old->localStartsAt->format('H:i'),
            'end_time' => $old->localEndsAt->format('H:i'),
            'pause_minutes' => $old->pause_minutes,
            'notes' => $old->notes,
        ];

        $replacement = $replace->handle($this->actor($request), $old, $request->only(self::FIELDS) + $defaults, $request->boolean('force'));

        return $this->respond($replacement)->response()->setStatusCode(201);
    }

    /**
     * Suppression groupée, tout ou rien : un seul shift interdit refuse toute la demande (403).
     */
    public function bulkDelete(Request $request, DeleteShifts $delete): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);

        $count = $delete->handle($actor, array_map('intval', $data['ids']));

        return response()->json(['data' => ['deleted' => $count]]);
    }

    /**
     * Le shift à gérer ou à lire. Voir la description de la classe pour le périmètre.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    protected function find(Request $request, int $id, bool $allowOwn = false): Shift
    {
        $actor = $this->actor($request);
        $canView = $this->authorizer()->allows($actor, 'shifts.view');

        if (! $canView && ! $allowOwn) {
            throw new AuthorizationException;
        }

        $shift = Shift::withTrashed()->with(self::RELATIONS)->findOrFail($id);
        $ids = $canView ? $this->authorizer()->storeIdsFor($actor, 'shifts.view') : [];

        $visible = $canView
            ? ($ids === null || in_array($shift->store_id, $ids, true) || ($allowOwn && $shift->user_id === $actor->id))
            : ($shift->user_id === $actor->id && ! $shift->trashed());

        if (! $visible) {
            throw (new ModelNotFoundException)->setModel(Shift::class, [$id]);
        }

        return $shift;
    }

    private function respond(Shift $shift): ShiftResource
    {
        return new ShiftResource($shift->refresh()->load(self::RELATIONS));
    }
}
