<?php

namespace Kintai\Bundles\PlanningCheck\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Kintai\Bundles\PlanningCheck\DaySchedule;
use Kintai\Bundles\PlanningCheck\Http\Requests\IndexRequest;
use Kintai\Bundles\PlanningCheck\Http\Requests\RejectRequest;
use Kintai\Bundles\PlanningCheck\Http\Requests\StoreScopeRequest;
use Kintai\Bundles\PlanningCheck\Http\Requests\SubmitRequest;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningCheckAccess;
use Kintai\Bundles\PlanningCheck\PlanningCheckActions;
use Kintai\Bundles\PlanningCheck\PlanningCheckPolicy;
use Kintai\Contracts\Api\ApiController;

/**
 * API de la validation du planning (`/api/v1/planning-check`). Mêmes droits que l'écran (`PlanningCheckAccess`) ; une
 * validation hors des magasins de l'appelant est introuvable (404). Le jour visé est toujours demain : l'appelant ne le choisit pas.
 */
class CheckController extends ApiController
{
    public function __construct(
        private readonly PlanningCheckAccess $access,
        private readonly PlanningCheckActions $actions,
        private readonly DaySchedule $schedule,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = app(IndexRequest::class)->validated();

        $checks = $this->access->visible($user)
            ->with(['store:id,name', 'submitter:id,name', 'validator:id,name'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('target_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('target_date', '<=', $filters['to']))
            ->orderByDesc('target_date')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return CheckResource::collection($checks->through(fn (PlanningCheck $row): array => $this->present($user, $row)));
    }

    public function show(Request $request, int $check): CheckResource
    {
        $user = $this->user($request);

        return new CheckResource($this->present($user, $this->find($user, $check)));
    }

    /**
     * Le planning de demain d'un magasin : l'état de la validation (nul s'il n'y en a pas encore) et les shifts.
     */
    public function tomorrow(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(StoreScopeRequest::class)->validated();
        $storeId = (int) $data['store_id'];

        if (! $this->access->canView($user, $storeId)) {
            abort(404);
        }

        $setting = PlanningCheckSetting::forStore($storeId);
        $date = PlanningCheckPolicy::targetDate();
        $check = PlanningCheck::query()->with(['store:id,name', 'submitter:id,name', 'validator:id,name'])->where('store_id', $storeId)->whereDate('target_date', $date)->first();
        $day = $this->schedule->forDay($storeId, $date, $setting->include_open_shifts);

        return response()->json(['data' => [
            'store_id' => $storeId,
            'date' => $date,
            'enabled' => $setting->enabled,
            'working_day' => PlanningCheckPolicy::isWorkingDay($setting, $date),
            'late' => PlanningCheckPolicy::isLate($setting) && ($check === null || $check->isOpen()),
            'deadline' => $setting->deadline_time,
            'check' => $check === null ? null : $this->present($user, $check),
            'shifts' => $day['rows'],
            'total_minutes' => $day['total_minutes'],
            'open_shifts' => $day['open'],
            'can_submit' => $this->access->allows($user, 'planning_check.submit', $storeId),
            'can_approve' => $this->access->allows($user, 'planning_check.approve', $storeId),
        ]]);
    }

    public function submit(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(SubmitRequest::class)->validated();

        $check = $this->actions->submit($user, (int) $data['store_id'], (bool) ($data['confirm_empty'] ?? false));

        return $this->respond($user, $check, 201);
    }

    public function sendNow(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(SubmitRequest::class)->validated();

        return $this->respond($user, $this->actions->sendNow($user, (int) $data['store_id'], (bool) ($data['confirm_empty'] ?? false)));
    }

    public function approve(Request $request, int $check): CheckResource
    {
        $user = $this->user($request);

        return new CheckResource($this->fresh($user, $this->actions->approve($user, $this->find($user, $check))));
    }

    public function reject(Request $request, int $check): CheckResource
    {
        $user = $this->user($request);
        $data = app(RejectRequest::class)->validated();

        return new CheckResource($this->fresh($user, $this->actions->reject($user, $this->find($user, $check), $data['reason'])));
    }

    public function resend(Request $request, int $check): CheckResource
    {
        $user = $this->user($request);

        return new CheckResource($this->fresh($user, $this->actions->resend($user, $this->find($user, $check))));
    }

    public function reopen(Request $request, int $check): CheckResource
    {
        $user = $this->user($request);

        return new CheckResource($this->fresh($user, $this->actions->reopen($user, $this->find($user, $check))));
    }

    private function respond(User $user, PlanningCheck $check, int $status = 200): JsonResponse
    {
        return (new CheckResource($this->fresh($user, $check)))->response()->setStatusCode($status);
    }

    /**
     * La validation telle qu'elle est après une action : les relations chargées avant l'action (soumetteur, valideur) sont périmées.
     *
     * @return array<string, mixed>
     */
    private function fresh(User $user, PlanningCheck $check): array
    {
        return $this->present($user, $check->unsetRelations());
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $id): PlanningCheck
    {
        $model = PlanningCheck::query()->with(['store:id,name', 'submitter:id,name', 'validator:id,name'])->findOrFail($id);

        if (! $this->access->canView($user, $model->store_id)) {
            abort(404);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, PlanningCheck $row): array
    {
        $row->loadMissing(['store:id,name', 'submitter:id,name', 'validator:id,name']);
        $approve = $this->access->allows($user, 'planning_check.approve', $row->store_id);

        return [
            'id' => $row->id,
            'store' => ['id' => $row->store_id, 'name' => $row->store->name],
            'target_date' => $row->target_date->toDateString(),
            'status' => $row->status,
            'confirmed_empty' => $row->confirmed_empty,
            'submitted_by' => $row->submitter === null ? null : ['id' => $row->submitter->id, 'name' => $row->submitter->name],
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'validated_by' => $row->validator === null ? null : ['id' => $row->validator->id, 'name' => $row->validator->name],
            'validated_at' => $row->validated_at?->toIso8601String(),
            'reject_reason' => $row->reject_reason,
            'send_status' => $row->send_status,
            'sent_at' => $row->sent_at?->toIso8601String(),
            'escalated_at' => $row->escalated_at?->toIso8601String(),
            'needs_resend' => $this->actions->needsResend($row),
            'can_submit' => $row->isOpen() && $this->access->allows($user, 'planning_check.submit', $row->store_id),
            'can_decide' => $row->status === PlanningCheck::SUBMITTED && $approve,
            'can_resend' => $row->status === PlanningCheck::VALIDATED && $approve,
        ];
    }
}
