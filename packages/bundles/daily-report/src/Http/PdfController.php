<?php

namespace Kintai\Bundles\DailyReport\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\DailyReportPdf;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * Le rapport en PDF : aperçu dans le navigateur (le même document, avec une barre d'outils) et téléchargement. Réservé à
 * qui peut ouvrir le rapport ; le téléchargement est journalisé.
 */
class PdfController extends Controller
{
    public function __construct(private readonly DailyReportAccess $access, private readonly DailyReportPdf $pdf) {}

    public function preview(Request $request, int $report): Response
    {
        $model = $this->find($request, $report);

        return response($this->pdf->html($model, null, true));
    }

    public function download(Request $request, int $report, ActivityRecorder $activity): Response
    {
        $model = $this->find($request, $report);
        $bytes = $this->pdf->bytes($model);
        abort_if($bytes === null, 500, __('The PDF could not be generated.'));

        /** @var User $user */
        $user = $request->user();
        $activity->record('daily_report.pdf_exported', $model, $model->store_id, ['date' => $model->report_date->toDateString()], $user);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.DailyReportPdf::filename($model).'"',
        ]);
    }

    private function find(Request $request, int $report): DailyReport
    {
        $model = DailyReport::query()->find($report);
        /** @var User $user */
        $user = $request->user();
        abort_if($model === null || ! $this->access->canView($user, $model), 404);

        return $model;
    }
}
