<?php

declare(strict_types=1);

namespace kintai\Core\Mail;

/** Transport d'envoi d'e-mail (mail() natif ou SMTP). */
interface MailTransportInterface
{
    /**
     * @param string[]              $to
     * @param string[]              $attachmentPaths Chemins absolus des pièces jointes
     * @param array<string, string> $extraHeaders    En-têtes ajoutés au message (voir MailHeaders)
     */
    public function send(
        string $fromAddress,
        string $fromName,
        array  $to,
        string $subject,
        string $htmlBody,
        array  $attachmentPaths = [],
        array  $extraHeaders = [],
    ): bool;
}
