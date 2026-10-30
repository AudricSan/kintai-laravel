<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;

/**
 * Envoi d'e-mails par le mailer de Laravel (Symfony Mailer) : SMTP ou sendmail selon config/mail.php, avec l'expéditeur
 * réglé par l'Owner par-dessus celui du fichier. Injectée dans tous les services qui envoient des mails.
 */
final class MailerService
{
    /** Adresse d'expédition d'origine de config/mail.php : tant qu'elle n'est pas changée, rien n'est réellement réglé. */
    private const DEFAULT_FROM = 'noreply@example.com';

    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $config configuration au format de config/mail.php (« default », « mailers », « from »)
     * @param MailerContract|null  $mailer remplace le mailer construit d'après la configuration (tests)
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?MailerContract $mailer = null,
    ) {}

    /** Transport choisi par la configuration : « smtp » ou « sendmail ». */
    public function driver(): string
    {
        return ($this->config['default'] ?? 'sendmail') === 'smtp' ? 'smtp' : 'sendmail';
    }

    /** Réglages affichés par la page de test d'e-mail de l'Owner (jamais le mot de passe). */
    public function summary(): array
    {
        $smtp = $this->config['mailers']['smtp'] ?? [];

        return [
            'driver' => $this->driver(),
            'from'   => $this->config['from'] ?? [],
            'smtp'   => [
                'host'       => (string) ($smtp['host'] ?? ''),
                'port'       => (string) ($smtp['port'] ?? ''),
                'username'   => (string) ($smtp['username'] ?? ''),
                'encryption' => ($smtp['scheme'] ?? 'smtps') === 'smtps' ? 'ssl' : 'tls',
            ],
        ];
    }

    /**
     * L'instance a-t-elle un envoi d'e-mail réglé ? SMTP : serveur et identifiant renseignés. sendmail : une adresse
     * d'expédition choisie (celle d'origine ne l'est pas) ; son bon fonctionnement ne peut pas se vérifier sans
     * envoyer (voir la page de test d'e-mail de l'Owner).
     */
    public function isConfigured(): bool
    {
        if ($this->driver() === 'smtp') {
            $smtp = $this->config['mailers']['smtp'] ?? [];

            return trim((string) ($smtp['host'] ?? '')) !== '' && trim((string) ($smtp['username'] ?? '')) !== '';
        }
        $from = (string) ($this->config['from']['address'] ?? self::DEFAULT_FROM);

        return $from !== self::DEFAULT_FROM && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Message de l'erreur du dernier envoi échoué (page de test d'e-mail), null si le dernier envoi a réussi. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param string[]              $to
     * @param string[]              $attachmentPaths  Chemins absolus vers les fichiers à joindre
     * @param array<string, string> $headers          En-têtes ajoutés au message (List-Unsubscribe…) ; un nom ou une
     *                                                valeur invalide (saut de ligne : injection d'en-têtes) est écarté
     * @param int|null              $timeout          Délai de connexion SMTP en secondes (30 par défaut) : un envoi fait
     *                                                pendant une requête utilisateur ne doit pas la bloquer longtemps
     */
    public function send(array $to, string $subject, string $htmlBody, array $attachmentPaths = [], array $headers = [], ?int $timeout = null): bool
    {
        $this->lastError = null;
        $validTo = array_values(array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if (empty($validTo)) {
            return false;
        }

        $address = (string) ($this->config['from']['address'] ?? self::DEFAULT_FROM);
        $name    = (string) ($this->config['from']['name'] ?? 'Kintai');

        try {
            $this->transport($timeout)->html($htmlBody, function (Message $message) use ($validTo, $subject, $address, $name, $attachmentPaths, $headers): void {
                $message->from($address, $name)->to($validTo)->subject($subject);
                foreach ($attachmentPaths as $path) {
                    if (is_file($path)) {
                        $message->attach($path);
                    }
                }
                foreach ($headers as $header => $value) {
                    if (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', (string) $header) === 1 && preg_match('/[\r\n]/', (string) $value) !== 1) {
                        $message->getSymfonyMessage()->getHeaders()->addTextHeader((string) $header, (string) $value);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            report($e);

            return false;
        }

        return true;
    }

    private function transport(?int $timeout): MailerContract
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        $mailer = $this->driver();
        $config = $this->config['mailers'][$mailer] ?? ['transport' => $mailer];
        if ($timeout !== null && $mailer === 'smtp') {
            $config['timeout'] = max(1, $timeout);
        }

        return app(MailManager::class)->build($config);
    }
}
