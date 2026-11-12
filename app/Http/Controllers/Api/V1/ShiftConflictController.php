<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Shifts\ResolveConflict;
use App\Http\Resources\V1\ShiftResource;
use App\Models\Shift;
use App\Planning\ShiftConflict;
use App\Planning\ShiftConflictFinder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Conflits entre shifts actifs (chevauchements d'un même employé, durées hors bornes du magasin) et leur résolution
 * (« garder le plus récent », action ResolveConflict). Droit : `shifts.view` ; la résolution exige `shifts.update`
 * sur les magasins des deux shifts.
 */
class ShiftConflictController extends ApiController
{
    /** Période maximale analysée, en jours. */
    private const MAX_DAYS = 93;

    public function index(Request $request, ShiftConflictFinder $finder): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, 'shifts.view')) {
            throw new AuthorizationException;
        }

        $filters = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = (string) config('kintai.timezone', 'UTC');
        $from = $filters['from'] ?? Carbon::now($tz)->startOfMonth()->toDateString();
        $to = $filters['to'] ?? Carbon::parse($from)->endOfMonth()->toDateString();

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('The period cannot exceed :days days.', ['days' => self::MAX_DAYS])]);
        }

        $ids = $this->authorizer()->storeIdsFor($actor, 'shifts.view');

        $shifts = Shift::query()
            ->with(['store', 'user', 'shiftType'])
            ->whereNull('replaced_by_id')
            ->when($ids !== null, fn ($query) => $query->whereIn('store_id', $ids))
            ->when(isset($filters['store_id']), fn ($query) => $query->where('store_id', (int) $filters['store_id']))
            ->whereBetween('shift_date', [$from, $to])
            ->orderBy('starts_at')
            ->get();

        $pairs = collect($finder->overlappingPairs($shifts))
            ->sortByDesc(fn (ShiftConflict $pair): int => $pair->first->starts_at->getTimestamp())
            ->values();

        return response()->json(['data' => [
            'overlaps' => $pairs->map(fn (ShiftConflict $pair): array => [
                'overlap_minutes' => $pair->overlapMinutes,
                'keep_id' => $pair->newer()->id,
                'first' => (new ShiftResource($pair->first))->resolve($request),
                'second' => (new ShiftResource($pair->second))->resolve($request),
            ])->all(),
            'too_short' => ShiftResource::collection($finder->tooShort($shifts))->resolve($request),
            'too_long' => ShiftResource::collection($finder->tooLong($shifts))->resolve($request),
        ]]);
    }

    /**
     * Garde le shift le plus récent des deux et remplace l'autre. `204` si l'un des deux a déjà disparu (rien à résoudre).
     */
    public function resolve(Request $request, ResolveConflict $resolve): JsonResponse|Response
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, 'shifts.view')) {
            throw new AuthorizationException;
        }

        $data = $request->validate(['first_id' => ['required', 'integer'], 'second_id' => ['required', 'integer']]);
        $first = $this->visible($request, (int) $data['first_id']);
        $second = $this->visible($request, (int) $data['second_id']);

        $kept = $resolve->handle($actor, $first, $second);

        if ($kept === null) {
            return response()->noContent();
        }

        return (new ShiftResource($kept->refresh()->load(['store', 'user', 'shiftType'])))->response();
    }

    private function visible(Request $request, int $id): Shift
    {
        $ids = $this->authorizer()->storeIdsFor($this->actor($request), 'shifts.view');
        $shift = Shift::withTrashed()->findOrFail($id);

        if ($ids !== null && ! in_array($shift->store_id, $ids, true)) {
            throw (new ModelNotFoundException)->setModel(Shift::class, [$id]);
        }

        return $shift;
    }
}
