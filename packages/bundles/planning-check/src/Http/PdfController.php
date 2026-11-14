<?php

namespace Kintai\Bundles\PlanningCheck\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Kintai\Bundles\PlanningCheck\DaySchedule;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningCheckAccess;
use Kintai\Bundles\PlanningCheck\PlanningPdf;

/**
 * Le PDF du planning d'une validation, généré à la demande avec les shifts d'aujourd'hui (pas ceux du jour de l'envoi).
 * Réservé à qui peut ouvrir l'écran du magasin.
 */
class PdfController extends Controller
{
    public function __invoke(Request $request, int $check, PlanningCheckAccess $access, DaySchedule $schedule, PlanningPdf $pdf): Response
    {
        /** @var User $user */
        $user = $request->user();
        $model = PlanningCheck::query()->with(['store', 'validator:id,name'])->find($check);
        abort_if($model === null || ! $access->canView($user, $model->store_id), 404);

        $date = $model->target_date->toDateString();
        $day = $schedule->forDay($model->store_id, $date, PlanningCheckSetting::query()->firstOrNew(['store_id' => $model->store_id], PlanningCheckSetting::DEFAULTS)->include_open_shifts);
        $bytes = $pdf->bytes($model->store, $date, $day, ['validated_by' => (string) ($model->validator->name ?? '')]);
        abort_if($bytes === null, 500, __('The PDF could not be generated.'));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.PlanningPdf::filename($model->store, $date).'"',
        ]);
    }
}
