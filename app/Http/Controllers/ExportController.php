<?php

namespace App\Http\Controllers;

use App\Actions\Exports\DownloadExport;
use App\Exports\ExportAccess;
use App\Exports\ExportDatasetRegistry;
use App\Exports\ExportTooLargeException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'un jeu de données (`/exports/download`). Les droits sont décidés par jeu de données et par magasin dans
 * `ExportAccess`, pas par une permission de route : chacun peut au moins exporter ses propres données.
 *
 * Paramètres : `dataset`, `format`, `target` (`me` = mes données, sinon les magasins), `stores[]`, `users[]`, `from`, `to`.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request, ExportDatasetRegistry $registry, ExportAccess $access, DownloadExport $download): StreamedResponse|RedirectResponse
    {
        $dataset = $registry->find((string) $request->query('dataset', ''));
        abort_if($dataset === null, 404);

        try {
            $scope = $access->scope(
                $request->user(),
                $dataset,
                $request->query('target') === 'me',
                $this->ids($request->query('stores')),
                $this->ids($request->query('users')),
                $this->string($request->query('from')),
                $this->string($request->query('to')),
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        try {
            return $download->handle($dataset, $scope, strtolower((string) $request->query('format', '')));
        } catch (ExportTooLargeException $e) {
            return redirect()->route('exports.index')->withErrors(['export' => $e->userMessage()]);
        }
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', array_filter($value, 'is_scalar'))) : [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
