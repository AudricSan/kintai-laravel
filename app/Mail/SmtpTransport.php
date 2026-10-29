<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoi SMTP par Symfony Mailer, le transport du mailer de Laravel : SSL direct (port 465), STARTTLS (587) ou sans
 * chiffrement, authentification choisie avec le serveur (LOGIN, PLAIN, CRAM-MD5…), message MIME construit par
 * symfony/mime (type de chaque pièce jointe deviné, encodage des en-têtes, Message-ID, Date).
 *
 * Remplace le client SMTP écrit à la main : même constructeur, même send(), même getLastError() pour la page de test
 * d'e-mail de l'Owner.
 */
final class SmtpTransport implements MailTransportInterface
{
    private ?string $lastError = null;

    /** @var (\Closure(): TransportInterface)|null */
    private readonly ?\Closure $transportFactory;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param string                              $encryption 'ssl' (SSL direct), 'tls' (STARTTLS) ou autre valeur (aucun)
     * @param (\Closure(): TransportInterface)|null $transportFactory remplace la connexion au serveur (tests)
     */
    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly int    $timeout = 30,
        ?\Closure $transportFactory = null,
    ) {
        $this->transportFactory = $transportFactory;
    }

    /**
     * @param string[] $to
     * @param string[] $attachmentPaths  Chemins absolus vers les pièces jointes
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
        $this->lastError = null;
        try {
            $this->transport()->send($this->message($fromAddress, $fromName, $to, $subject, $htmlBody, $attachmentPaths, $extraHeaders));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[Kintai][SMTP] ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Message envoyé, tel que construit pour send() (exposé pour les tests).
     *
     * @param string[]              $to
     * @param string[]              $attachmentPaths
     * @param array<string, string> $extraHeaders
     */
    public function message(
        string $fromAddress,
        string $fromName,
        array  $to,
        string $subject,
        string $htmlBody,
        array  $attachmentPaths = [],
        array  $extraHeaders = [],
    ): Email {
        $email = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to(...array_map(static fn(string $address): Address => new Address(trim($address)), $to))
            ->subject($subject)
            ->html($htmlBody);

        foreach ($attachmentPaths as $path) {
            if (is_file($path)) {
                $email->attachFromPath($path, basename($path));
            }
        }

        // Mêmes règles qu'avant : un en-tête au nom invalide ou à la valeur multiligne est écarté (MailHeaders).
        foreach (MailHeaders::format($extraHeaders) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }

    private function transport(): TransportInterface
    {
        if ($this->transportFactory !== null) {
            return ($this->transportFactory)();
        }

        $encryption = strtolower(trim($this->encryption));
        // 'ssl' : TLS dès la connexion. 'tls' : STARTTLS exigé, comme avant (un serveur qui ne le propose pas est
        // refusé, rien ne part en clair). Toute autre valeur : sans chiffrement.
        $transport = new EsmtpTransport($this->host, $this->port, $encryption === 'ssl');
        $transport->setAutoTls($encryption === 'tls');
        $transport->setRequireTls($encryption === 'tls');
        if ($this->username !== '') {
            $transport->setUsername($this->username);
            $transport->setPassword($this->password);
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(max(1, $this->timeout));
        }

        return $transport;
    }
}
