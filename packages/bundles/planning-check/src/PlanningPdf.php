<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\Store;
use Illuminate\Support\Facades\App;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Le planning d'un jour en PDF (mPDF, généré à la volée : rien n'est stocké). Langue du document : celle du magasin, sinon
 * celle de l'instance.
 */
class PlanningPdf
{
    public static function view(): string
    {
        return PlanningCheckServiceProvider::SLUG.'::pdf';
    }

    public function locale(Store $store): string
    {
        $locale = (string) $store->locale;

        return in_array($locale, ['fr', 'en', 'ja'], true) ? $locale : (string) config('app.locale');
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, total_minutes: int, assigned: int, open: int}  $day
     * @param  array{validated_by?: string, updated?: bool, generated_at?: string}  $context
     */
    public function html(Store $store, string $date, array $day, array $context = []): string
    {
        $locale = $this->locale($store);
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            return app('view')->make(self::view(), ['store' => $store, 'date' => $date, 'day' => $day, 'context' => $context, 'locale' => $locale])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Octets du PDF ; null si la génération échoue.
     *
     * @param  array{rows: list<array<string, mixed>>, total_minutes: int, assigned: int, open: int}  $day
     * @param  array{validated_by?: string, updated?: bool, generated_at?: string}  $context
     */
    public function bytes(Store $store, string $date, array $day, array $context = []): ?string
    {
        try {
            $html = $this->html($store, $date, $day, $context);
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
            $pdf->SetTitle($store->name.' - '.$date);
            $pdf->WriteHTML($html);

            return $pdf->Output('', Destination::STRING_RETURN);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function filename(Store $store, string $date): string
    {
        return 'planning-'.str_replace('-', '', $date).'-store'.$store->id.'.pdf';
    }
}
