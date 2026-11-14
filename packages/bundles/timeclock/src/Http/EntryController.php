<?php

namespace Kintai\Bundles\Timeclock\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Kintai\Bundles\Timeclock\Http\Requests\ClockInRequest;
use Kintai\Bundles\Timeclock\Http\Requests\ClockOutRequest;
use Kintai\Bundles\Timeclock\Http\Requests\IndexRequest;
use Kintai\Bundles\Timeclock\Http\Requests\UpdateRequest;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Bundles\Timeclock\TimeclockAccess;
use Kintai\Bundles\Timeclock\TimeclockActions;
use Kintai\Contracts\Api\ApiController;

/**
 * API du pointage (`/api/v1/timeclock`). Chacun pointe (`clock-in`, `clock-out`, avec `client_time` pour un geste fait hors
 * ligne) et lit ses pointages ; les gérants lisent, corrigent et suppriment ceux de leurs magasins avec `timeclock.view`,
 * `timeclock.update`, `timeclock.delete`. Un pointage hors du périmètre de l'appelant est introuvable (404).
 */
class EntryController extends ApiController
{
    public function __construct(private readonly TimeclockAccess $access, private readonly TimeclockActions $actions) {}

    public function clockIn(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(ClockInRequest::class)->validated();

        $entry = $this->actions->clockIn($user, $data['store_id'] ?? null, $data['client_time'] ?? null, $request->ip());

        return (new EntryResource($this->present($user, $entry->load(['user:id,name', 'store:id,name', 'shift']))))->response()->setStatusCode(201);
    }

    public function clockOut(Request $request): EntryResource
    {
        $user = $this->user($request);
        $data = app(ClockOutRequest::class)->validated();

        $entry = $this->actions->clockOut($user, $data['client_time'] ?? null, $request->ip());

        return new EntryResource($this->present($user, $entry->load(['user:id,name', 'store:id,name', 'shift'])));
    }

    /**
     * Le pointage en cours de l'appelant (`data` nul s'il n'est pas en poste).
     */
    public function active(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $entry = $this->actions->active($user)?->load(['user:id,name', 'store:id,name', 'shift']);

        return response()->json(['data' => $entry === null ? null : $this->present($user, $entry)]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = app(IndexRequest::class)->validated();

        $entries = $this->access->visible($user)
            ->with(['user:id,name', 'store:id,name', 'shift'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('shift_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('shift_date', '<=', $filters['to']))
            ->when(isset($filters['active']), fn ($q) => $filters['active'] ? $q->whereNull('clock_out_at') : $q->whereNotNull('clock_out_at'))
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return EntryResource::collection($entries->through(fn (Timeclock $entry): array => $this->present($user, $entry)));
    }

    public function show(Request $request, int $entry): EntryResource
    {
        $user = $this->user($request);

        return new EntryResource($this->present($user, $this->find($user, $entry)));
    }

    /**
     * Corrige les heures (ISO 8601, `clock_out_at` nul = en cours) et la note.
     */
    public function update(Request $request, int $entry): EntryResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $entry);

        $data = app(UpdateRequest::class)->validated();

        $in = isset($data['clock_in_at']) ? Carbon::parse($data['clock_in_at'])->utc() : $model->clock_in_at;
        $out = array_key_exists('clock_out_at', $data)
            ? ($data['clock_out_at'] === null ? null : Carbon::parse($data['clock_out_at'])->utc())
            : $model->clock_out_at;

        $updated = $this->actions->update($user, $model, $in, $out, array_key_exists('notes', $data) ? $data['notes'] : $model->notes);

        return new EntryResource($this->present($user, $updated->load(['user:id,name', 'store:id,name', 'shift'])));
    }

    public function destroy(Request $request, int $entry): Response
    {
        $user = $this->user($request);
        $this->actions->delete($user, $this->find($user, $entry));

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $id): Timeclock
    {
        $entry = Timeclock::query()->with(['user:id,name', 'store:id,name', 'shift'])->findOrFail($id);

        if (! $this->access->canView($user, $entry)) {
            abort(404);
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, Timeclock $entry): array
    {
        return [
            'id' => $entry->id,
            'user' => ['id' => $entry->user_id, 'name' => $entry->user->name],
            'store' => ['id' => $entry->store_id, 'name' => $entry->store->name],
            'shift_id' => $entry->shift_id,
            'date' => $entry->shift_date->toDateString(),
            'clock_in_at' => $entry->clock_in_at->toIso8601String(),
            'clock_out_at' => $entry->clock_out_at?->toIso8601String(),
            'duration_minutes' => $entry->duration_minutes,
            'late_minutes' => $entry->lateMinutes($this->access->lateThreshold()),
            'running' => $entry->isActive(),
            'notes' => $entry->notes,
            'can_edit' => $this->access->canUpdate($user, $entry),
            'can_delete' => $this->access->canDelete($user, $entry),
        ];
    }
}
