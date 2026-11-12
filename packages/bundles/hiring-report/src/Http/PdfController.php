<?php

namespace Kintai\Bundles\HiringReport\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kintai\Bundles\HiringReport\HiringReportAccess;
use Kintai\Bundles\HiringReport\HiringReportPdf;
use Kintai\Bundles\HiringReport\Models\HiringReport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le rapport en PDF : aperçu dans le navigateur (le même document, avec une barre d'outils) et téléchargement. Réservé à
 * qui peut ouvrir le rapport (`hiring_reports.view`) dans son magasin : un rapport hors du périmètre est introuvable
 * (404). Rien n'est stocké ni mis en cache. La réponse contient des données personnelles : ni cache ni devinette du
 * navigateur.
 */
class PdfController extends Controller
{
    public function __construct(private readonly HiringReportAccess $access, private readonly HiringReportPdf $pdf) {}

    public function preview(Request $request, int $report): Response
    {
        $model = $this->find($request, $report);

        return $this->sealed(response($this->pdf->html($model, true)));
    }

    public function download(Request $request, int $report): Response
    {
        $model = $this->find($request, $report);
        $bytes = $this->pdf->bytes($model);
        abort_if($bytes === null, 500, __('The PDF could not be generated.'));

        return $this->sealed(response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.HiringReportPdf::filename($model).'"',
        ]));
    }

    private function find(Request $request, int $report): HiringReport
    {
        $model = HiringReport::query()->find($report);
        /** @var User $user */
        $user = $request->user();
        abort_if($model === null || ! $this->access->canView($user, $model), 404);

        return $model;
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function sealed(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
