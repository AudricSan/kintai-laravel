<?php

namespace Kintai\Bundles\SalaryReport\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\SalaryReportAccess;
use Kintai\Bundles\SalaryReport\SalaryReportPdf;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * Le rapport en PDF : aperçu dans le navigateur (le même document, avec une barre d'outils) et téléchargement. Réservé à
 * qui peut ouvrir le rapport (`salary_reports.view`) et l'exporter (`salary_reports.export`) dans son magasin : un rapport
 * hors du périmètre est introuvable (404), un rapport visible mais non exportable est refusé (403). Rien n'est stocké ni
 * mis en cache, le téléchargement est journalisé.
 */
class PdfController extends Controller
{
    use SealsResponses;

    public function __construct(private readonly SalaryReportAccess $access, private readonly SalaryReportPdf $pdf) {}

    public function preview(Request $request, int $report): Response
    {
        $model = $this->find($request, $report);

        return $this->sealed(response($this->pdf->html($model, true)));
    }

    public function download(Request $request, int $report, ActivityRecorder $activity): Response
    {
        $model = $this->find($request, $report);
        $bytes = $this->pdf->bytes($model);
        abort_if($bytes === null, 500, __('The PDF could not be generated.'));

        /** @var User $user */
        $user = $request->user();
        $activity->record('salary_report.pdf_exported', $model, $model->store_id, ['month' => $model->target_month], $user);

        return $this->sealed(response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.SalaryReportPdf::filename($model).'"',
        ]));
    }

    private function find(Request $request, int $report): SalaryReport
    {
        $model = SalaryReport::query()->find($report);
        /** @var User $user */
        $user = $request->user();
        abort_if($model === null || ! $this->access->canView($user, $model), 404);
        abort_unless($this->access->canExport($user, $model), 403);

        return $model;
    }
}
