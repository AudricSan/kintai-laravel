<?php

namespace Kintai\Bundles\DailyReport;

use Illuminate\Support\Facades\App;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Le rapport en HTML (aperçu du navigateur, avec une barre d'outils) et en PDF (mPDF, généré à la volée : rien n'est stocké).
 */
class DailyReportPdf
{
    public function __construct(private readonly DayShifts $shifts) {}

    /**
     * @param  string|null  $locale  Langue du document ; absente : celle de la requête.
     */
    public function html(DailyReport $report, ?string $locale = null, bool $preview = false): string
    {
        $report->loadMissing(['store', 'author']);
        $locale ??= App::getLocale();

        $setting = DailyReportSetting::query()->firstOrNew(['store_id' => $report->store_id], DailyReportSetting::DEFAULTS);
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            return app('view')->make(self::view(), [
                'report' => $report,
                'store' => $report->store,
                'author' => $report->author,
                'rows' => $this->shifts->forReportDate($report->store_id, $report->report_date),
                'columns' => $setting->columns(),
                'locale' => $locale,
                'preview' => $preview,
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Octets du PDF ; null si la génération échoue (l'appelant le signale).
     */
    public function bytes(DailyReport $report, ?string $locale = null): ?string
    {
        try {
            $html = $this->html($report, $locale);
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
            $pdf->SetTitle(__('Daily report').' - '.$report->report_date->toDateString());
            $pdf->WriteHTML($html);

            return $pdf->Output('', Destination::STRING_RETURN);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Nom de la vue du document. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
     * statique, qui le voit désactivé, ne doit pas la prendre pour une vue manquante.
     */
    public static function view(): string
    {
        return DailyReportServiceProvider::SLUG.'::pdf';
    }

    public static function filename(DailyReport $report): string
    {
        return 'daily-report-'.$report->report_date->format('Ymd').'-store'.$report->store_id.'.pdf';
    }
}
