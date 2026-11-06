<?php

namespace Kintai\Bundles\DailyReport;

use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Kintai\Bundles\DailyReport\Models\DailyReport;

/**
 * Le rapport validé envoyé à un destinataire : résumé dans le corps (dans la langue du destinataire, posée par
 * l'expéditeur avec `locale()`), PDF en pièce jointe s'il a pu être généré.
 */
class DailyReportMail extends Mailable
{
    public function __construct(public readonly DailyReport $report, public readonly ?string $pdf = null) {}

    public static function viewName(): string
    {
        return DailyReportServiceProvider::SLUG.'::mail';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->report->store->name.'] '.__('Daily report').' - '.$this->report->report_date->toDateString());
    }

    public function content(): Content
    {
        $store = $this->report->store;

        return new Content(view: self::viewName(), with: [
            'report' => $this->report,
            'store' => $store,
            'sales' => Money::forStore($this->report->sales_total, $store),
            'labor' => Money::forStore($this->report->labor_cost, $store),
            'waste' => Money::forStore($this->report->waste_total, $store),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        return [Attachment::fromData(fn (): string => (string) $this->pdf, DailyReportPdf::filename($this->report))->withMime('application/pdf')];
    }
}
