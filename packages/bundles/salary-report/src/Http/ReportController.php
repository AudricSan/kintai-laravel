<?php

namespace Kintai\Bundles\SalaryReport\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Kintai\Bundles\SalaryReport\Http\Requests\CalculateRequest;
use Kintai\Bundles\SalaryReport\Http\Requests\IndexRequest;
use Kintai\Bundles\SalaryReport\Http\Requests\StoreRequest;
use Kintai\Bundles\SalaryReport\Http\Requests\UpdateRequest;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\SalaryReportAccess;
use Kintai\Bundles\SalaryReport\SalaryReportActions;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Api\ApiController;

/**
 * API des rapports de salaire (`/api/v1/salary-report/reports`). Mêmes droits que les écrans (`SalaryReportAccess`),
 * un par action : lecture `salary_reports.view`, calcul / création / modification `salary_reports.generate`, suppression
 * `salary_reports.delete`. Un rapport hors des magasins où l'appelant a `view` est introuvable (404), jamais « interdit »,
 * pour ne pas révéler son existence. Le magasin, le mois et l'employé s'imposent à la création et ne changent plus.
 */
class ReportController extends ApiController
{
    use SealsResponses;

    public function __construct(private readonly SalaryReportAccess $access, private readonly SalaryReportActions $actions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = app(IndexRequest::class)->validated();

        $reports = $this->access->visible($user)
            ->with(['store:id,name', 'employee:id,name', 'personInCharge:id,name'])
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['employee_id']), fn ($q) => $q->where('user_id', (int) $filters['employee_id']))
            ->when(isset($filters['month']), fn ($q) => $q->where('target_month', $filters['month']))
            ->when(isset($filters['year']), fn ($q) => $q->where('target_month', 'like', $filters['year'].'-%'))
            ->orderByDesc('target_month')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ReportResource::collection($reports->through(fn (SalaryReport $row): array => $this->present($user, $row)));
    }

    public function show(Request $request, int $report, ActivityRecorder $activity): ReportResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $report);
        $activity->record('salary_report.viewed', $model, $model->store_id, ['month' => $model->target_month], $user);

        return new ReportResource($this->present($user, $model));
    }

    /**
     * Calcule sans enregistrer : valeurs proposées et détail pour une période d'un magasin (ou d'un de ses employés).
     */
    public function calculate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(CalculateRequest::class)->validated();

        $result = $this->actions->calculate($user, (int) $data['store_id'], $data['from'], $data['to'], isset($data['employee_id']) ? (int) $data['employee_id'] : null);

        return $this->sealed(response()->json(['data' => $result]));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = app(StoreRequest::class)->validated();

        $report = $this->actions->create($user, (int) $data['store_id'], $data['month'], isset($data['employee_id']) ? (int) $data['employee_id'] : null, $data);

        return $this->sealed((new ReportResource($this->present($user, $report)))->response()->setStatusCode(201));
    }

    public function update(Request $request, int $report): ReportResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $report);

        return new ReportResource($this->present($user, $this->actions->update($user, $model, app(UpdateRequest::class)->validated())));
    }

    public function destroy(Request $request, int $report): Response
    {
        $user = $this->user($request);
        $this->actions->delete($user, $this->find($user, $report));

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $id): SalaryReport
    {
        $model = SalaryReport::query()->findOrFail($id);

        if (! $this->access->canView($user, $model)) {
            abort(404);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, SalaryReport $row): array
    {
        return ReportResource::payload($row) + [
            'can_edit' => $this->access->canEdit($user, $row),
            'can_delete' => $this->access->canDelete($user, $row),
            'can_export' => $this->access->canExport($user, $row),
        ];
    }
}
