<?php

namespace Kintai\Bundles\Timeoff\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Kintai\Bundles\Timeoff\Http\Requests\IndexRequest;
use Kintai\Bundles\Timeoff\Http\Requests\RefuseRequest;
use Kintai\Bundles\Timeoff\Http\Requests\StoreRequest;
use Kintai\Bundles\Timeoff\Http\Requests\UpdateRequest;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Bundles\Timeoff\TimeoffAccess;
use Kintai\Bundles\Timeoff\TimeoffActions;
use Kintai\Contracts\Api\ApiController;

/**
 * API des congés (`/api/v1/timeoff/requests`). Chacun voit et dépose ses propres demandes (membre d'un magasin avec la
 * fonctionnalité), l'annule tant qu'elle est en attente ; les gérants voient et traitent celles de leurs magasins avec
 * `timeoff.view`, `timeoff.create`, `timeoff.update`, `timeoff.approve`, `timeoff.delete`. Une demande hors du périmètre
 * de l'appelant est introuvable (404).
 */
class RequestController extends ApiController
{
    public function __construct(private readonly TimeoffAccess $access, private readonly TimeoffActions $actions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = app(IndexRequest::class)->validated();

        $requests = $this->access->visible($user)
            ->with(['user:id,name', 'store:id,name'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('end_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('start_date', '<=', $filters['to']))
            ->orderByDesc('start_date')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return RequestResource::collection($requests->through(fn (TimeoffRequest $row): array => $this->present($user, $row)));
    }

    public function show(Request $request, int $timeoff): RequestResource
    {
        $user = $this->user($request);

        return new RequestResource($this->present($user, $this->find($user, $timeoff)));
    }

    /**
     * Dépose une demande pour soi (en attente) ; avec `user_id` d'un autre employé, saisit un congé approuvé d'emblée
     * (`timeoff.create` sur le magasin).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(StoreRequest::class)->validated();

        $end = $data['end_date'] ?? $data['start_date'];
        $targetId = $data['user_id'] ?? $user->id;

        if ($targetId === $user->id) {
            $created = $this->actions->submit($user, (int) $data['store_id'], $data['type'], $data['start_date'], $end, $data['reason'] ?? null);
        } else {
            $employee = User::query()->findOrFail($targetId);
            $created = $this->actions->createFor($user, $employee, (int) $data['store_id'], $data['type'], $data['start_date'], $end, $data['reason'] ?? null);
        }

        return (new RequestResource($this->present($user, $created->load(['user:id,name', 'store:id,name']))))->response()->setStatusCode(201);
    }

    /**
     * Corrige type, dates, motif ou note (une clé absente garde sa valeur). Le statut se change par approve / refuse / cancel.
     */
    public function update(Request $request, int $timeoff): RequestResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $timeoff);

        $data = app(UpdateRequest::class)->validated();

        $start = $data['start_date'] ?? $model->start_date->toDateString();
        $end = $data['end_date'] ?? $model->end_date->toDateString();
        validator(['start' => $start, 'end' => $end], ['end' => ['after_or_equal:start']])->validate();

        return new RequestResource($this->present($user, $this->actions->update($user, $model, $data)));
    }

    public function destroy(Request $request, int $timeoff): Response
    {
        $user = $this->user($request);
        $this->actions->delete($user, $this->find($user, $timeoff));

        return response()->noContent();
    }

    public function approve(Request $request, int $timeoff): RequestResource
    {
        $user = $this->user($request);

        return new RequestResource($this->present($user, $this->actions->decide($user, $this->find($user, $timeoff), true, $request->input('admin_note'))));
    }

    public function refuse(Request $request, int $timeoff): RequestResource
    {
        $user = $this->user($request);
        app(RefuseRequest::class);

        return new RequestResource($this->present($user, $this->actions->decide($user, $this->find($user, $timeoff), false, $request->input('admin_note'))));
    }

    public function cancel(Request $request, int $timeoff): RequestResource
    {
        $user = $this->user($request);

        return new RequestResource($this->present($user, $this->actions->cancel($user, $this->find($user, $timeoff))));
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $timeoff): TimeoffRequest
    {
        $model = TimeoffRequest::query()->with(['user:id,name', 'store:id,name'])->findOrFail($timeoff);

        if (! $this->access->canView($user, $model)) {
            abort(404);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, TimeoffRequest $row): array
    {
        return [
            'id' => $row->id,
            'user' => ['id' => $row->user_id, 'name' => $row->user->name],
            'store' => ['id' => $row->store_id, 'name' => $row->store->name],
            'type' => $row->type,
            'status' => $row->status,
            'start_date' => $row->start_date->toDateString(),
            'end_date' => $row->end_date->toDateString(),
            'reason' => $row->reason,
            'admin_note' => $row->admin_note,
            'processed_by' => $row->processed_by,
            'processed_at' => $row->processed_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'can_cancel' => $this->access->canCancel($user, $row),
            'can_decide' => $row->isPending() && $this->access->canApprove($user, $row),
        ];
    }
}
