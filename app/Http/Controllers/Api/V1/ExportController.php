<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exports\DownloadExport;
use App\Exports\ExportAccess;
use App\Exports\ExportDatasetRegistry;
use App\Exports\ExportTooLargeException;
use App\Exports\ExportWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports de l'application web (CSV, Excel, PDF, ICS). Les droits sont décidés par jeu de données et par magasin dans
 * `ExportAccess`, comme sur le web ; le téléchargement journalise l'export (`DownloadExport`).
 */
class ExportController extends ApiController
{
    /**
     * Jeux de données que l'utilisateur peut exporter.
     */
    public function index(Request $request, ExportAccess $access): JsonResponse
    {
        $data = [];

        foreach ($access->available($this->actor($request)) as $key => $entry) {
            $dataset = $entry['dataset'];
            $data[] = [
                'key' => $key,
                'label' => __($dataset->label()),
                'formats' => $dataset->formats(),
                'has_period' => $dataset->hasPeriod(),
                'per_employee' => $dataset->perEmployee(),
                'store_ids' => $entry['stores'],
                'self_service' => $entry['self'],
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Téléchargement. Paramètres : `format` (requis), `target=me` (mes données), `stores[]`, `users[]`, `from`, `to`.
     */
    public function download(Request $request, string $dataset, ExportDatasetRegistry $registry, ExportAccess $access, DownloadExport $download): StreamedResponse
    {
        $definition = $registry->find($dataset);

        if ($definition === null) {
            abort(404);
        }

        $data = $request->validate([
            'format' => ['required', Rule::in(ExportWriter::FORMATS)],
            'target' => ['nullable', Rule::in(['me', 'stores'])],
            'stores' => ['nullable', 'array'],
            'stores.*' => ['integer'],
            'users' => ['nullable', 'array'],
            'users.*' => ['integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Un périmètre non permis lève AuthorizationException (403).
        $scope = $access->scope(
            $this->actor($request),
            $definition,
            ($data['target'] ?? null) === 'me',
            array_map('intval', $data['stores'] ?? []),
            array_map('intval', $data['users'] ?? []),
            $data['from'] ?? null,
            $data['to'] ?? null,
        );

        try {
            return $download->handle($definition, $scope, $data['format']);
        } catch (ExportTooLargeException $e) {
            throw ValidationException::withMessages(['export' => $e->userMessage()]);
        }
    }
}
