<?php

namespace Kintai\Bundles\SalaryReport\Http;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Kintai\Bundles\SalaryReport\Http\Requests\ExportFilterRequest;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\SalaryReportAccess;
use Kintai\Bundles\SalaryReport\SalaryReportPdf;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * Export de la liste des rapports (JSON et PDF), avec les filtres de la liste. Réservé à `salary_reports.export` : seuls les
 * rapports des magasins où l'on a à la fois `view` et `export` sortent, un magasin demandé hors de ce périmètre est
 * refusé (403). Rien n'est stocké ni mis en cache, chaque export est journalisé (nombre de rapports, jamais leur contenu).
 */
class ExportController extends Controller
{
    use SealsResponses;

    private const LIMIT = 2000;

    public function __construct(private readonly SalaryReportAccess $access, private readonly SalaryReportPdf $pdf, private readonly ActivityRecorder $activity) {}

    public function json(Request $request): Response
    {
        $reports = $this->reports($request);
        $this->activity->record('salary_report.list_exported', null, null, ['format' => 'json', 'count' => $reports->count()], $this->user($request));

        return $this->sealed(response((string) json_encode(['data' => $reports->map(fn (SalaryReport $report): array => ReportResource::payload($report))->all()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.SalaryReportPdf::listFilename('json').'"',
        ]));
    }

    public function preview(Request $request): Response
    {
        return $this->sealed(response($this->pdf->listHtml($this->reports($request), true)));
    }

    public function download(Request $request): Response
    {
        $reports = $this->reports($request);
        $bytes = $this->pdf->listBytes($reports);
        abort_if($bytes === null, 500, __('The PDF could not be generated.'));

        $this->activity->record('salary_report.list_exported', null, null, ['format' => 'pdf', 'count' => $reports->count()], $this->user($request));

        return $this->sealed(response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.SalaryReportPdf::listFilename('pdf').'"',
        ]));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return Collection<int, SalaryReport>
     */
    private function reports(Request $request): Collection
    {
        $user = $this->user($request);
        $stores = $this->access->exportableStores($user);
        abort_if($stores === [], 403);

        $filters = app(ExportFilterRequest::class)->validated();

        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        abort_if($storeId !== null && ! isset($stores[$storeId]), 403);

        return SalaryReport::query()
            ->with(['store:id,name,currency,currency_symbol_style', 'employee:id,name', 'personInCharge:id,name'])
            ->whereIn('store_id', $storeId === null ? array_keys($stores) : [$storeId])
            ->when(isset($filters['year']), fn ($q) => $q->where('target_month', 'like', $filters['year'].'-%'))
            ->when(isset($filters['month']), fn ($q) => $q->where('target_month', 'like', '%-'.str_pad((string) $filters['month'], 2, '0', STR_PAD_LEFT)))
            ->when(isset($filters['employee_id']), fn ($q) => $q->where('user_id', (int) $filters['employee_id']))
            ->orderByDesc('target_month')
            ->orderBy('store_id')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
