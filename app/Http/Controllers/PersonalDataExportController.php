<?php

namespace App\Http\Controllers;

use App\Actions\Exports\ExportPersonalData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Exporter mes données » (RGPD) : fichier JSON des données de l'utilisateur connecté.
 */
class PersonalDataExportController extends Controller
{
    public function __invoke(Request $request, ExportPersonalData $export): StreamedResponse
    {
        $user = $request->user();
        $data = $export->handle($user);

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'kintai-data-'.$user->id.'.json', ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
