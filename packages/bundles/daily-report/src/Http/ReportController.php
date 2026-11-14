<?php

namespace Kintai\Bundles\DailyReport\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\DailyReportActions;
use Kintai\Bundles\DailyReport\Http\Requests\IndexRequest;
use Kintai\Bundles\DailyReport\Http\Requests\StoreRequest;
use Kintai\Bundles\DailyReport\Http\Requests\UpdateRequest;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Contracts\Api\ApiController;

/**
 * API des rapports journaliers (`/api/v1/daily-report/reports`). Mêmes droits que les écrans (`DailyReportAccess`) : on
 * voit tous les rapports des magasins où l'on a `daily_reports.view`, les siens ailleurs ; un rapport hors du périmètre de
 * l'appelant est introuvable (404). Seuls les champs de contenu s'écrivent : l'auteur, le magasin et l'état sont imposés.
 */
class ReportController extends ApiController
{
    public function __construct(private readonly DailyReportAccess $access, private readonly DailyReportActions $actions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = app(IndexRequest::class)->validated();

        $reports = $this->access->visible($user)
            ->with(['store:id,name', 'author:id,name'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['author_id']), fn ($q) => $q->where('author_id', (int) $filters['author_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['date']), fn ($q) => $q->whereDate('report_date', $filters['date']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('report_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('report_date', '<=', $filters['to']))
            ->orderByDesc('report_date')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ReportResource::collection($reports->through(fn (DailyReport $row): array => $this->present($user, $row)));
    }

    public function show(Request $request, int $report): ReportResource
    {
        $user = $this->user($request);

        return new ReportResource($this->present($user, $this->find($user, $report)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(StoreRequest::class)->validated();

        $report = $this->actions->create($user, (int) $data['store_id'], $data);

        return (new ReportResource($this->present($user, $report->load(['store:id,name', 'author:id,name']))))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $report): ReportResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $report);
        $data = app(UpdateRequest::class)->validated();

        return new ReportResource($this->present($user, $this->actions->update($user, $model, $data)));
    }

    public function destroy(Request $request, int $report): Response
    {
        $user = $this->user($request);
        $this->actions->delete($user, $this->find($user, $report));

        return response()->noContent();
    }

    public function submit(Request $request, int $report): ReportResource
    {
        $user = $this->user($request);

        return new ReportResource($this->present($user, $this->actions->submit($user, $this->find($user, $report))));
    }

    public function validateReport(Request $request, int $report): ReportResource
    {
        $user = $this->user($request);

        return new ReportResource($this->present($user, $this->actions->validate($user, $this->find($user, $report))));
    }

    public function sendMail(Request $request, int $report): ReportResource
    {
        $user = $this->user($request);

        return new ReportResource($this->present($user, $this->actions->sendMail($user, $this->find($user, $report))));
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $id): DailyReport
    {
        $model = DailyReport::query()->with(['store:id,name', 'author:id,name', 'validator:id,name'])->findOrFail($id);

        if (! $this->access->canView($user, $model)) {
            abort(404);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, DailyReport $row): array
    {
        $row->loadMissing(['store:id,name', 'author:id,name']);

        return [
            'id' => $row->id,
            'store' => ['id' => $row->store_id, 'name' => $row->store->name],
            'author' => $row->author === null ? null : ['id' => $row->author->id, 'name' => $row->author->name],
            'report_date' => $row->report_date->toDateString(),
            'sales_total' => $row->sales_total,
            'customer_count' => $row->customer_count,
            'labor_cost' => $row->labor_cost,
            'waste_total' => $row->waste_total,
            'notes' => $row->notes,
            'status' => $row->status,
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'validated_by' => $row->validated_by,
            'validated_at' => $row->validated_at?->toIso8601String(),
            'mail_sent_at' => $row->mail_sent_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'can_edit' => $this->access->canEdit($user, $row),
            'can_submit' => $this->access->canSubmit($user, $row),
            'can_validate' => $this->access->canValidate($user, $row),
            'can_send_mail' => $this->access->canSendMail($user, $row),
            'can_delete' => $this->access->canDelete($user, $row),
        ];
    }
}
