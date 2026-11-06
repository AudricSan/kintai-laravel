<?php

namespace Kintai\Bundles\SalaryReport;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Un rapport, ou une liste de rapports, en HTML (aperçu du navigateur avec une barre d'outils) et en PDF (mPDF, généré à la
 * volée : rien n'est écrit sur le disque, hors le dossier temporaire de mPDF). Ne vérifie aucun droit : les contrôleurs le
 * font avant d'appeler.
 */
class SalaryReportPdf
{
    public function __construct(private readonly SalaryCalculator $calculator) {}

    public function html(SalaryReport $report, bool $preview = false): string
    {
        $report->loadMissing(['store', 'employee', 'personInCharge']);

        return app('view')->make(self::view('pdf'), [
            'report' => $report,
            'store' => $report->store,
            'detail' => $this->calculator->detailFor($report),
            'names' => $this->names($report),
            'locale' => App::getLocale(),
            'preview' => $preview,
        ])->render();
    }

    /**
     * @param  Collection<int, SalaryReport>  $reports
     */
    public function listHtml(Collection $reports, bool $preview = false): string
    {
        $reports->loadMissing(['store', 'employee']);

        return app('view')->make(self::view('pdf-list'), [
            'reports' => $reports,
            'locale' => App::getLocale(),
            'preview' => $preview,
        ])->render();
    }

    public function bytes(SalaryReport $report): ?string
    {
        return $this->toPdf($this->html($report), __('Salary report').' - '.$report->target_month);
    }

    /**
     * @param  Collection<int, SalaryReport>  $reports
     */
    public function listBytes(Collection $reports): ?string
    {
        return $this->toPdf($this->listHtml($reports), __('Salary reports'));
    }

    /**
     * Nom d'une vue du bundle. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
     * statique, qui le voit désactivé, ne doit pas les prendre pour des vues manquantes.
     */
    public static function view(string $name): string
    {
        return SalaryReportServiceProvider::SLUG.'::'.$name;
    }

    public static function filename(SalaryReport $report): string
    {
        return 'salary-report-'.str_replace('-', '', $report->target_month).'-store'.$report->store_id.($report->user_id === null ? '' : '-employee'.$report->user_id).'.pdf';
    }

    public static function listFilename(string $extension): string
    {
        return 'salary-reports-'.now()->format('Ymd').'.'.$extension;
    }

    /**
     * Noms des employés listés dans le rapport (heures par employé), relus à l'affichage.
     *
     * @return array<int, string>
     */
    private function names(SalaryReport $report): array
    {
        $ids = array_map(fn (array $row): int => $row['user_id'], $report->employee_hours ?? []);

        return $ids === [] ? [] : User::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Octets du PDF ; null si la génération échoue (l'appelant le signale, sans détail : le HTML contient des salaires).
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
            // Le message d'une exception mPDF peut citer un extrait du HTML (donc des montants) : seule sa classe est tracée.
            logger()->error('Salary report PDF generation failed.', ['exception' => $e::class]);

            return null;
        }
    }
}
