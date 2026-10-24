<?php

declare(strict_types=1);

namespace kintai\Core\Mail;

/**
 * Facade mail : choisit le transport (native ou SMTP) selon config/mail.php.
 * Injectée dans tous les services qui envoient des mails.
 */
final class MailerService
{
    /** Adresse d'expédition d'origine de config/mail.php : tant qu'elle n'est pas changée, rien n'est réellement réglé. */
    private const DEFAULT_FROM = 'noreply@example.com';

    private readonly string $fromAddress;
    private readonly string $fromName;
    private readonly string $driver;
    /** @var array<string, mixed> */
    private readonly array $smtp;
    private readonly MailTransportInterface $transport;
    private readonly bool $injected;

    /** @param MailTransportInterface|null $transport remplace le transport choisi par la configuration (tests) */
    public function __construct(array $config, ?MailTransportInterface $transport = null)
    {
        $this->fromAddress = $config['from']['address'] ?? self::DEFAULT_FROM;
        $this->fromName    = $config['from']['name']    ?? 'Kintai';
        $this->driver      = (string) ($config['driver'] ?? 'native');
        $this->smtp        = $config['smtp'] ?? [];

        $this->injected  = $transport !== null;
        $this->transport = $transport ?? ($this->driver === 'smtp' ? $this->smtpTransport() : new NativeTransport());
    }

    /** Transport choisi par la configuration : « smtp » ou « native ». */
    public function driver(): string
    {
        return $this->driver === 'smtp' ? 'smtp' : 'native';
    }

    /**
     * L'instance a-t-elle un envoi d'e-mail réglé ? SMTP : serveur et identifiant renseignés. mail() natif : une
     * adresse d'expédition choisie (celle d'origine ne l'est pas) ; son bon fonctionnement ne peut pas se vérifier
     * sans envoyer (voir la page de test d'e-mail de l'Owner).
     */
    public function isConfigured(): bool
    {
        if ($this->driver === 'smtp') {
            return trim((string) ($this->smtp['host'] ?? '')) !== '' && trim((string) ($this->smtp['username'] ?? '')) !== '';
        }
        return $this->fromAddress !== self::DEFAULT_FROM && filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param string[]              $to
     * @param string[]              $attachmentPaths  Chemins absolus vers les fichiers à joindre
     * @param array<string, string> $headers          En-têtes ajoutés au message (List-Unsubscribe…)
     * @param int|null              $timeout          Délai de connexion SMTP en secondes (30 par défaut) : un envoi fait
     *                                                pendant une requête utilisateur ne doit pas la bloquer longtemps
     */
    public function send(array $to, string $subject, string $htmlBody, array $attachmentPaths = [], array $headers = [], ?int $timeout = null): bool
    {
        $validTo = array_values(array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if (empty($validTo)) {
            return false;
        }

        $transport = $timeout !== null && $this->driver === 'smtp' && !$this->injected
            ? $this->smtpTransport($timeout)
            : $this->transport;

        return $transport->send(
            fromAddress:     $this->fromAddress,
            fromName:        $this->fromName,
            to:              $validTo,
            subject:         $subject,
            htmlBody:        $htmlBody,
            attachmentPaths: $attachmentPaths,
            extraHeaders:    $headers,
        );
    }

    private function smtpTransport(int $timeout = 30): SmtpTransport
    {
        return new SmtpTransport(
            host:       (string) ($this->smtp['host']       ?? 'localhost'),
            port:       (int)    ($this->smtp['port']       ?? 587),
            username:   (string) ($this->smtp['username']   ?? ''),
            password:   (string) ($this->smtp['password']   ?? ''),
            encryption: (string) ($this->smtp['encryption'] ?? 'tls'),
            timeout:    max(1, $timeout),
        );
    }
}
