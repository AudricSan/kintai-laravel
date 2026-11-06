<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\Store;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le planning de demain envoyé à un destinataire : message court (dans sa langue, posée par l'expéditeur avec `locale()`)
 * avec le PDF en pièce jointe. Aucun lien d'action : le destinataire ne répond pas par e-mail.
 */
class PlanningMail extends Mailable
{
    /**
     * @param  array{validated_by?: string, updated?: bool, generated_at?: string}  $context
     */
    public function __construct(public readonly Store $store, public readonly string $date, public readonly string $pdf, public readonly array $context = []) {}

    public static function viewName(): string
    {
        return PlanningCheckServiceProvider::SLUG.'::mail';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->store->name.'] '.__('Schedule of :date', ['date' => $this->date]).(empty($this->context['updated']) ? '' : ' - '.__('updated version')));
    }

    public function content(): Content
    {
        return new Content(view: self::viewName(), with: ['store' => $this->store, 'date' => $this->date, 'context' => $this->context]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, PlanningPdf::filename($this->store, $this->date))->withMime('application/pdf')];
    }
}
