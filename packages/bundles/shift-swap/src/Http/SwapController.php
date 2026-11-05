<?php

namespace Kintai\Bundles\ShiftSwap\Http;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Bundles\ShiftSwap\SwapAccess;
use Kintai\Bundles\ShiftSwap\SwapActions;
use Kintai\Contracts\Api\ApiController;

/**
 * API des échanges (`/api/v1/shift-swap/requests`). Chacun voit ses demandes (demandeur ou collègue), les propose, y
 * répond et les retire ; les gérants voient et traitent celles de leurs magasins avec `swaps.view`, `swaps.create`,
 * `swaps.update`, `swaps.approve`, `swaps.delete`. Une demande hors du périmètre de l'appelant est introuvable (404).
 *
 * `approve` et `apply` écrivent dans le planning : tant que le Core relève des avertissements, ils répondent `409
 * shift_warnings` (liste des avertissements) et ne font rien ; renvoyer la requête avec `force` à vrai les confirme.
 */
class SwapController extends ApiController
{
    public function __construct(private readonly SwapAccess $access, private readonly SwapActions $actions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(ShiftSwapRequest::STATUSES)],
            'stage' => ['nullable', Rule::in(['waiting_colleague', 'waiting_manager'])],
            'mine' => ['nullable', 'boolean'],
        ]);

        $swaps = $this->access->visible($user)
            ->with(['requester:id,name', 'target:id,name', 'store:id,name', 'requesterShift', 'targetShift'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['stage'] ?? null) === 'waiting_colleague', fn ($q) => $q->where('status', ShiftSwapRequest::PENDING)->whereNull('accepted_at'))
            ->when(($filters['stage'] ?? null) === 'waiting_manager', fn ($q) => $q->where('status', ShiftSwapRequest::PENDING)->whereNotNull('accepted_at'))
            ->when($request->boolean('mine'), fn ($q) => $q->where(fn ($own) => $own->where('requester_id', $user->id)->orWhere('target_user_id', $user->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return SwapResource::collection($swaps->through(fn (ShiftSwapRequest $row): array => $this->present($user, $row)));
    }

    public function show(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);

        return new SwapResource($this->present($user, $this->find($user, $swap)));
    }

    /**
     * Propose l'un de ses shifts à venir contre un shift à venir d'un collègue du même magasin.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'requester_shift_id' => ['required', 'integer'],
            'target_shift_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $created = $this->actions->request($user, (int) $data['requester_shift_id'], (int) $data['target_shift_id'], $data['reason'] ?? null);

        return (new SwapResource($this->present($user, $created)))->response()->setStatusCode(201);
    }

    /**
     * Gérant (`swaps.create`) : applique d'emblée l'échange de deux shifts de deux employés du même magasin.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'requester_shift_id' => ['required', 'integer'],
            'target_shift_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'force' => ['nullable', 'boolean'],
        ]);

        $created = $this->actions->createApplied(
            $user, (int) $data['requester_shift_id'], (int) $data['target_shift_id'], $data['reason'] ?? null, $request->boolean('force'),
        );

        return (new SwapResource($this->present($user, $created)))->response()->setStatusCode(201);
    }

    /**
     * Corrige le motif d'une demande en attente (`swaps.update`).
     */
    public function update(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $swap);
        $data = $request->validate(['reason' => ['present', 'nullable', 'string', 'max:2000']]);

        return new SwapResource($this->present($user, $this->actions->updateReason($user, $model, $data['reason'])));
    }

    public function destroy(Request $request, int $swap): Response
    {
        $user = $this->user($request);
        $this->actions->delete($user, $this->find($user, $swap));

        return response()->noContent();
    }

    public function accept(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);

        return new SwapResource($this->present($user, $this->actions->answer($user, $this->find($user, $swap), true)));
    }

    public function decline(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);

        return new SwapResource($this->present($user, $this->actions->answer($user, $this->find($user, $swap), false)));
    }

    public function cancel(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);

        return new SwapResource($this->present($user, $this->actions->cancel($user, $this->find($user, $swap))));
    }

    public function approve(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);
        $request->validate(['force' => ['nullable', 'boolean']]);

        return new SwapResource($this->present($user, $this->actions->approve($user, $this->find($user, $swap), $request->boolean('force'))));
    }

    public function refuse(Request $request, int $swap): SwapResource
    {
        $user = $this->user($request);

        return new SwapResource($this->present($user, $this->actions->refuse($user, $this->find($user, $swap))));
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $swap): ShiftSwapRequest
    {
        $model = ShiftSwapRequest::query()->findOrFail($swap);

        if (! $this->access->canView($user, $model)) {
            abort(404);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, ShiftSwapRequest $row): array
    {
        $row->load(['requester:id,name', 'target:id,name', 'store:id,name', 'requesterShift', 'targetShift']);

        return [
            'id' => $row->id,
            'store' => ['id' => $row->store_id, 'name' => $row->store->name],
            'requester' => ['id' => $row->requester_id, 'name' => $row->requester->name],
            'target' => ['id' => $row->target_user_id, 'name' => $row->target->name],
            'requester_shift' => $this->shift($row->requesterShift, $row->requester_shift_id),
            'target_shift' => $this->shift($row->targetShift, $row->target_shift_id),
            'status' => $row->status,
            'stage' => $row->stage(),
            'reason' => $row->reason,
            'accepted_at' => $row->accepted_at?->toIso8601String(),
            'approved_by' => $row->approved_by_id,
            'approved_at' => $row->approved_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'can_answer' => $this->access->canAnswer($user, $row),
            'can_cancel' => $this->access->canCancel($user, $row),
            'can_approve' => $row->awaitsManager() && $this->access->canApprove($user, $row),
            'can_refuse' => $row->isPending() && $this->access->canApprove($user, $row),
            'can_delete' => ! $row->isPending() && $this->access->canDelete($user, $row),
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
