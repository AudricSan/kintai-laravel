<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exports\ExportOwnSchedule;
use App\Actions\Exports\ExportPersonalData;
use App\Http\Requests\Api\V1\MeScheduleExportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports personnels : toujours les données de l'utilisateur authentifié, aucun identifiant d'employé dans la requête.
 */
class MeExportController extends ApiController
{
    /**
     * Planning de l'utilisateur dans un de ses magasins : `store` (obligatoire), `format` (`json` par défaut, ou `ics`),
     * `from` et `to` (YYYY-MM-DD ; 90 jours à partir d'aujourd'hui par défaut, 366 jours au plus).
     */
    public function schedule(Request $request, ExportOwnSchedule $export): StreamedResponse
    {
        $user = $this->actor($request);

        $data = $this->validated(MeScheduleExportRequest::class);

        $store = $user->stores()->where('stores.id', $data['store'])->firstOrFail();

        return $export->handle($user, $store, $data['format'] ?? 'json', $data['from'] ?? null, $data['to'] ?? null);
    }

    /**
     * Export RGPD : les données personnelles de l'utilisateur (JSON).
     */
    public function data(Request $request, ExportPersonalData $export): JsonResponse
    {
        return response()->json($export->handle($this->actor($request)), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
