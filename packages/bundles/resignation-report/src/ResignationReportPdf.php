<?php

namespace Kintai\Bundles\ResignationReport;

use Illuminate\Support\Facades\App;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Un rapport de démission en HTML (aperçu du navigateur avec une barre d'outils) et en PDF (mPDF, généré à la volée : rien
 * n'est écrit sur le disque, hors le dossier temporaire de mPDF). Ne vérifie aucun droit : le contrôleur le fait avant.
 */
class ResignationReportPdf
{
    public function html(ResignationReport $report, bool $preview = false): string
    {
        $report->loadMissing('store');

        return app('view')->make(self::view('pdf'), [
            'report' => $report,
            'store' => $report->store,
            'locale' => App::getLocale(),
            'preview' => $preview,
        ])->render();
    }

    public function bytes(ResignationReport $report): ?string
    {
        return $this->toPdf($this->html($report), __('Resignation report'));
    }

    /**
     * Nom d'une vue du bundle. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
     * statique, qui le voit désactivé, ne doit pas les prendre pour des vues manquantes.
     */
    public static function view(string $name): string
    {
        return ResignationReportServiceProvider::SLUG.'::'.$name;
    }

    public static function filename(ResignationReport $report): string
    {
        return 'resignation-report-'.$report->id.'.pdf';
    }

    /**
     * Octets du PDF ; null si la génération échoue (l'appelant le signale, sans détail : le HTML contient des données personnelles).
     */
    private function toPdf(string $html, string $title): ?string
    {
        try {
            $directory = storage_path('app/mpdf');
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $defaults = (new ConfigVariables)->getDefaults();
            $fonts = (new FontVariables)->getDefaults();

            $pdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 16,
                'margin_bottom' => 16,
                'tempDir' => $directory,
                'fontDir' => $defaults['fontDir'],
                'fontdata' => $fonts['fontdata'],
                'default_font' => 'dejavusans',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
            ]);
            $pdf->SetTitle($title);
            $pdf->WriteHTML($html);

            return $pdf->Output('', Destination::STRING_RETURN);
        } catch (Throwable $e) {
            // Le message d'une exception mPDF peut citer un extrait du HTML (donc des données personnelles) : seule sa classe est tracée.
            logger()->error('Resignation report PDF generation failed.', ['exception' => $e::class]);

            return null;
        }
    }
}
