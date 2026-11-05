<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mail de test envoyé depuis l'écran des réglages : expéditeur et transport sont ceux des vrais envois.
 */
class MailTestMessage extends Mailable
{
    public function __construct(public readonly string $sentAt, public readonly string $transport) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Test email from :app', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('This is a test email sent from the Kintai settings. If you can read it, outgoing email works.')).'</p>'
            .'<p><small>'.e(__('Sent at :date with the ":mailer" mailer.', ['date' => $this->sentAt, 'mailer' => $this->transport])).'</small></p>');
    }
}
