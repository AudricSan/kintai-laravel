<?php

namespace Kintai\Bundles\DailyReport;

use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Throwable;

/**
 * Envoi du rapport validé aux destinataires du magasin : un e-mail par personne (les adresses ne se voient pas), dans la
 * langue de son compte. Les destinataires sont relus à l'envoi : un compte désactivé, sans adresse ou sorti du magasin
 * ne reçoit plus rien.
 */
class DailyReportMailer
{
    public function __construct(private readonly DailyReportPdf $pdf) {}

    /**
     * @return list<User>
     */
    public function recipients(int $storeId): array
    {
        $ids = DailyReportSetting::query()->firstOrNew(['store_id' => $storeId], DailyReportSetting::DEFAULTS)->recipientIds();
        if ($ids === []) {
            return [];
        }

        $members = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->whereIn('user_id', $ids)->pluck('user_id');

        return User::query()->whereIn('id', $members)->where('is_active', true)->whereNotNull('email')->orderBy('name')->get()->all();
    }

    /**
     * Envoie le rapport ; vrai si au moins un e-mail est parti. Un échec est signalé (journal d'erreurs), jamais levé.
     *
     * @param  string|null  $pdfLocale  Langue du PDF ; absente : celle de la requête.
     */
    public function send(DailyReport $report, ?string $pdfLocale = null): bool
    {
        $recipients = $this->recipients($report->store_id);
        if ($recipients === []) {
            return false;
        }

        $report->loadMissing('store');
        $bytes = $this->pdf->bytes($report, $pdfLocale);
        $sent = false;

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send((new DailyReportMail($report, $bytes))->locale($recipient->preferredLocale()));
                $sent = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
