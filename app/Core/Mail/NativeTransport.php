<?php

declare(strict_types=1);

namespace kintai\Core\Mail;

/**
 * Transport mail via la fonction mail() native de PHP.
 * Fonctionne sur tout serveur avec sendmail / MTA configuré
 * (OVH mutualisé, VPS avec postfix…).
 *
 * Sur Windows/XAMPP, mail() nécessite un SMTP configuré dans php.ini
 * (SMTP = ..., smtp_port = ...). Utiliser SmtpTransport sinon.
 *
 * Requis sur OVH : l'adresse From doit être un e-mail du domaine hébergé.
 */
final class NativeTransport implements MailTransportInterface
{
    /**
     * @param string[] $to
     * @param string[] $attachmentPaths
     */
    public function send(
        string $fromAddress,
        string $fromName,
        array  $to,
        string $subject,
        string $htmlBody,
        array  $attachmentPaths = [],
        array  $extraHeaders = [],
    ): bool {
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
        $fromHeader     = $fromName !== ''
            ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $fromAddress . '>'
            : $fromAddress;
        $dateHeader     = date('r');
        $messageId      = '<' . bin2hex(random_bytes(12)) . '@' . (gethostname() ?: 'localhost') . '>';

        $validTo = array_values(array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if (empty($validTo)) {
            return false;
        }

        if (empty($attachmentPaths)) {
            $headers = implode("\r\n", [
                'Date: ' . $dateHeader,
                'Message-ID: ' . $messageId,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
                'From: ' . $fromHeader,
                ...MailHeaders::format($extraHeaders),
            ]);
            $body    = quoted_printable_encode($htmlBody);
            $success = false;
            foreach ($validTo as $recipient) {
                error_clear_last();
                $sent = @mail($recipient, $encodedSubject, $body, $headers, '-f' . $fromAddress);
                if ($sent) {
                    $success = true;
                } else {
                    $phpErr = error_get_last()['message'] ?? 'aucune erreur PHP';
                    error_log('[Kintai][Mail] Échec mail() vers ' . $recipient . ' — ' . $phpErr);
                }
            }
            return $success;
        }

        $boundary = '----=_Part_' . bin2hex(random_bytes(8));
        $headers  = implode("\r\n", [
            'Date: ' . $dateHeader,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'From: ' . $fromHeader,
            ...MailHeaders::format($extraHeaders),
        ]);

        $message = "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($htmlBody) . "\r\n";

        foreach ($attachmentPaths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $filename = basename($path);
            $encoded  = chunk_split(base64_encode((string) file_get_contents($path)));
            $message .= "\r\n--{$boundary}\r\n"
                . "Content-Type: application/pdf; name=\"{$filename}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n"
                . $encoded;
        }

        $message .= "\r\n--{$boundary}--";

        $success = false;
        foreach ($validTo as $recipient) {
            error_clear_last();
            $sent = @mail($recipient, $encodedSubject, $message, $headers, '-f' . $fromAddress);
            if ($sent) {
                $success = true;
            } else {
                $phpErr = error_get_last()['message'] ?? 'aucune erreur PHP';
                error_log('[Kintai][Mail] Échec mail() vers ' . $recipient . ' — ' . $phpErr);
            }
        }
        return $success;
    }
}
