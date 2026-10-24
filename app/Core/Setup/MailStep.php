<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

use kintai\Core\Mail\MailerService;
use kintai\Core\Request;
use kintai\Core\Services\EnvFileWriter;

/**
 * E-mail : transport (mail() natif ou SMTP), expéditeur et identifiants, écrits dans le fichier d'environnement de
 * l'instance (MAIL_*, même mécanisme que la langue de l'instance) puisque config/mail.php les lit de l'environnement.
 * Le bouton « Envoyer un e-mail de test » essaie la configuration saisie avant de la garder.
 *
 * Le mot de passe SMTP n'est jamais réaffiché : un champ laissé vide conserve l'actuel.
 */
final class MailStep implements SetupStepInterface
{
    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /**
     * @param ?EnvFileWriter $env  écrivain du fichier d'environnement (remplacé en test)
     */
    public function __construct(
        private readonly MailerService $mailer,
        private readonly ?EnvFileWriter $env = null,
    ) {}

    /** @var ?\Closure fn(array $config): MailerService, mailer construit pour l'essai (remplacé en test) */
    private ?\Closure $factory = null;

    /**
     * Remplace la construction du mailer d'essai (tests). Pas un paramètre du constructeur : le conteneur instancie chaque
     * paramètre typé par une classe et ne sait pas fournir une Closure.
     *
     * @param \Closure(array): MailerService $factory
     */
    public function useMailerFactory(\Closure $factory): void
    {
        $this->factory = $factory;
    }

    public function id(): string
    {
        return 'mail';
    }

    public function data(Request $request): array
    {
        return [
            'driver'     => $this->mailer->driver(),
            'configured' => $this->mailer->isConfigured(),
            'from'       => (string) env('MAIL_FROM_ADDRESS', ''),
            'from_name'  => (string) env('MAIL_FROM_NAME', 'Kintai'),
            'host'       => (string) env('MAIL_HOST', ''),
            'port'       => (string) env('MAIL_PORT', '465'),
            'username'   => (string) env('MAIL_USERNAME', ''),
            'has_password' => (string) env('MAIL_PASSWORD', '') !== '',
            'encryption' => (string) env('MAIL_ENCRYPTION', 'ssl'),
            'encryptions' => self::ENCRYPTIONS,
        ];
    }

    public function handle(Request $request, int $userId): array
    {
        $values = $this->validated($request);
        if (is_string($values)) {
            return [$values];
        }
        try {
            $writer = $this->env ?? new EnvFileWriter();
            foreach ($values as $key => $value) {
                $writer->set($key, $value);
            }
        } catch (\Throwable) {
            return ['setup_mail_not_saved'];
        }
        return [];
    }

    /**
     * Essaie la configuration saisie, sans l'enregistrer : envoie un e-mail de test à `test_to`.
     *
     * @return array{success: bool, error: ?string} erreur = clé de traduction ou message du transport
     */
    public function sendTest(Request $request): array
    {
        $values = $this->validated($request);
        if (is_string($values)) {
            return ['success' => false, 'error' => $values];
        }
        $to = trim((string) $request->post('test_to', ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'mail_test_error_invalid_email'];
        }

        // Le mot de passe saisi vide = celui déjà enregistré.
        $password = $values['MAIL_PASSWORD'] ?? (string) env('MAIL_PASSWORD', '');
        $config = [
            'driver' => $values['MAIL_DRIVER'],
            'from'   => ['address' => $values['MAIL_FROM_ADDRESS'], 'name' => $values['MAIL_FROM_NAME']],
            'smtp'   => [
                'host'       => $values['MAIL_HOST'] ?? '',
                'port'       => (int) ($values['MAIL_PORT'] ?? 465),
                'username'   => $values['MAIL_USERNAME'] ?? '',
                'password'   => $password,
                'encryption' => $values['MAIL_ENCRYPTION'] ?? 'ssl',
            ],
        ];
        try {
            $mailer = $this->factory !== null ? ($this->factory)($config) : new MailerService($config);
            $sent = $mailer->send([$to], __('mail_test_subject', ['date' => date('Y-m-d H:i:s')]), '<p>' . __('mail_test_body_intro') . '</p>', [], [], 10);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        return $sent ? ['success' => true, 'error' => null] : ['success' => false, 'error' => 'setup_mail_test_failed'];
    }

    /**
     * Valeurs MAIL_* à écrire d'après la saisie, ou la clé de traduction de l'erreur.
     *
     * @return array<string, string>|string
     */
    public function validated(Request $request): array|string
    {
        $driver = $request->post('driver', 'native') === 'smtp' ? 'smtp' : 'native';
        $from   = trim((string) $request->post('from', ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'setup_mail_from_invalid';
        }
        $values = [
            'MAIL_DRIVER'       => $driver,
            'MAIL_FROM_ADDRESS' => $from,
            'MAIL_FROM_NAME'    => substr(trim((string) $request->post('from_name', 'Kintai')) ?: 'Kintai', 0, 100),
        ];
        if ($driver === 'smtp') {
            $host = trim((string) $request->post('host', ''));
            $port = (int) $request->post('port', '0');
            $enc  = (string) $request->post('encryption', 'ssl');
            if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1 || $port < 1 || $port > 65535 || !in_array($enc, self::ENCRYPTIONS, true)) {
                return 'setup_mail_smtp_invalid';
            }
            $values += [
                'MAIL_HOST'       => $host,
                'MAIL_PORT'       => (string) $port,
                'MAIL_USERNAME'   => trim((string) $request->post('username', '')),
                'MAIL_ENCRYPTION' => $enc,
            ];
            $password = (string) $request->post('password', '');
            if ($password !== '') {
                $values['MAIL_PASSWORD'] = $password;
            }
        }
        return $values;
    }

    public function summary(): ?string
    {
        return $this->mailer->isConfigured() ? strtoupper($this->mailer->driver()) : null;
    }
}
