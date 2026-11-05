<?php

namespace Kintai\Bundles\ShiftClaim\Http;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Kintai\Bundles\ShiftClaim\ClaimAccess;
use Kintai\Bundles\ShiftClaim\ClaimActions;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;
use Kintai\Contracts\Api\ApiController;

/**
 * API de la bourse aux shifts (`/api/v1/shift-claim`). Chacun voit la bourse de ses magasins, y candidate et retire ses
 * candidatures ; les gérants voient et traitent celles de leurs magasins avec `open_shifts.view`, `open_shifts.publish`
 * (proposer ou retirer un shift) et `open_shifts.approve` (approuver, rejeter). Un shift ou une candidature hors du
 * périmètre de l'appelant est introuvable (404).
 *
 * `approve` écrit dans le planning : tant que le Core relève des avertissements, il répond `409 shift_warnings` (liste
 * des avertissements) et ne fait rien ; renvoyer la requête avec `force` à vrai les confirme.
 */
class ClaimController extends ApiController
{
    public function __construct(private readonly ClaimAccess $access, private readonly ClaimActions $actions) {}

    /**
     * La bourse : shifts ouverts et proposés, à venir, des magasins de l'appelant (membre actif, fonctionnalité activée),
     * plus ceux des magasins où il a `open_shifts.view`.
     */
    public function openShifts(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = $request->validate(['store_id' => ['nullable', 'integer']]);

        $storeIds = array_unique([
            ...array_keys($this->access->memberStores($user)),
            ...array_keys($this->access->storesWith($user, 'open_shifts.view')),
        ]);

        if (isset($filters['store_id'])) {
            $storeIds = array_values(array_intersect($storeIds, [(int) $filters['store_id']]));
        }

        $shifts = $this->access->pool($storeIds)->with(['store:id,name', 'user:id,name'])->paginate($this->perPage($request));
        $mine = ShiftClaim::query()->where('user_id', $user->id)->whereIn('shift_id', $shifts->pluck('id'))->get()->keyBy('shift_id');
        $offers = ShiftOffer::query()->whereIn('shift_id', $shifts->pluck('id'))->pluck('note', 'shift_id');

        return ClaimResource::collection($shifts->through(fn (Shift $shift): array => $this->presentShift($user, $shift, $mine->get($shift->id), $offers)));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(ShiftClaim::STATUSES)],
            'mine' => ['nullable', 'boolean'],
        ]);

        $claims = $this->access->visible($user)
            ->with(['user:id,name', 'store:id,name', 'shift'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['shift_id']), fn ($q) => $q->where('shift_id', (int) $filters['shift_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ClaimResource::collection($claims->through(fn (ShiftClaim $claim): array => $this->present($user, $claim)));
    }

    public function show(Request $request, int $claim): ClaimResource
    {
        $user = $this->user($request);

        return new ClaimResource($this->present($user, $this->find($user, $claim)));
    }

    /**
     * Candidate à un shift de la bourse.
     */
    public function claim(Request $request, int $shift): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $claim = $this->actions->claim($user, $shift, $data['note'] ?? null);

        return (new ClaimResource($this->present($user, $claim)))->response()->setStatusCode(201);
    }

    public function withdraw(Request $request, int $claim): ClaimResource
    {
        $user = $this->user($request);

        return new ClaimResource($this->present($user, $this->actions->withdraw($user, $this->find($user, $claim))));
    }

    public function approve(Request $request, int $claim): ClaimResource
    {
        $user = $this->user($request);
        $request->validate(['force' => ['nullable', 'boolean']]);

        return new ClaimResource($this->present($user, $this->actions->approve($user, $this->find($user, $claim), $request->boolean('force'))));
    }

    public function reject(Request $request, int $claim): ClaimResource
    {
        $user = $this->user($request);

        return new ClaimResource($this->present($user, $this->actions->reject($user, $this->find($user, $claim))));
    }

    /**
     * Propose un shift attribué à la bourse (`open_shifts.publish`).
     */
    public function offer(Request $request, int $shift): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->actions->offer($user, $shift, $data['note'] ?? null);
        $model = Shift::query()->with(['store:id,name', 'user:id,name'])->findOrFail($shift);

        return (new ClaimResource($this->presentShift($user, $model, null, ShiftOffer::query()->where('shift_id', $shift)->pluck('note', 'shift_id'))))->response()->setStatusCode(201);
    }

    /**
     * Retire un shift de la bourse (`open_shifts.publish`).
     */
    public function unoffer(Request $request, int $shift): Response
    {
        $this->actions->unoffer($this->user($request), $shift);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $claim): ShiftClaim
    {
        $model = ShiftClaim::query()->findOrFail($claim);

        if (! $this->access->canView($user, $model)) {
            abort(404);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, ShiftClaim $claim): array
    {
        $claim->load(['user:id,name', 'store:id,name', 'shift']);

        return [
            'id' => $claim->id,
            'shift' => $this->shift($claim->shift, $claim->shift_id),
            'store' => ['id' => $claim->store_id, 'name' => $claim->store->name],
            'applicant' => ['id' => $claim->user_id, 'name' => $claim->user->name],
            'status' => $claim->status,
            'note' => $claim->note,
            'resolved_by' => $claim->resolved_by,
            'resolved_at' => $claim->resolved_at?->toIso8601String(),
            'created_at' => $claim->created_at?->toIso8601String(),
            'can_withdraw' => $this->access->canWithdraw($user, $claim),
            'can_decide' => $claim->isPending() && $this->access->canApprove($user, $claim),
        ];
    }

    /**
     * @param  Collection<int, string|null>  $offers
     * @return array<string, mixed>
     */
    private function presentShift(User $user, Shift $shift, ?ShiftClaim $mine, $offers): array
    {
        return $this->shift($shift, $shift->id) + [
            'store' => ['id' => $shift->store_id, 'name' => $shift->store->name],
            'holder' => $shift->user === null ? null : ['id' => $shift->user_id, 'name' => $shift->user->name],
            'offered' => $offers->has($shift->id),
            'note' => $offers->get($shift->id) ?: $shift->notes,
            'my_claim' => $mine === null ? null : ['id' => $mine->id, 'status' => $mine->status],
            'can_claim' => $shift->user_id !== $user->id && isset($this->access->memberStores($user)[$shift->store_id]) && ! in_array($mine?->status, [ShiftClaim::PENDING, ShiftClaim::APPROVED], true),
            'can_unoffer' => $offers->has($shift->id) && $this->access->canPublish($user, $shift->store_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shift(?Shift $shift, int $id): array
    {
        return [
            'id' => $id,
            'date' => $shift?->shift_date->toDateString(),
            'start' => $shift?->localStartsAt->format('H:i'),
            'end' => $shift?->localEndsAt->format('H:i'),
        ];
    }
}
