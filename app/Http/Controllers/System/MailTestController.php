<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Mail\MailerService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class MailTestController
{
    public function __construct(
        private readonly ViewFactory $view,
        private readonly MailerService $mailer,
    ) {}

    public function show(Request $request): Response
    {
        return response($this->view->make('system.mail-test', [
            'title'      => __('mailtest_title'),
            'mailConfig' => $this->loadConfig(),
            'phpIni'     => $this->phpIniInfo(),
            'result'     => null,
            'last_to'    => '',
        ])->render());
    }

    public function send(Request $request): Response
    {
        $to = trim((string) $request->post('to', ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result = ['success' => false, 'error' => __('mail_test_error_invalid_email')];
        } else {
            $result = $this->attemptSend($to);
        }

        return response($this->view->make('system.mail-test', [
            'title'      => __('mailtest_title'),
            'mailConfig' => $this->loadConfig(),
            'phpIni'     => $this->phpIniInfo(),
            'result'     => $result,
            'last_to'    => $to,
        ])->render());
    }

    private function attemptSend(string $to): array
    {
        $config  = $this->loadConfig();
        $subject = __('mail_test_subject', ['date' => date('Y-m-d H:i:s')]);
        $body    = '<p>' . __('mail_test_body_intro') . '</p>'
                 . '<p><small>' . __('mail_test_body_sent_at', [
                     'date'   => date('d/m/Y à H:i:s'),
                     'driver' => '<strong>' . htmlspecialchars($config['driver']) . '</strong>',
                 ]) . '</small></p>';

        if ($this->mailer->send([$to], $subject, $body)) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => $this->mailer->lastError() ?? __($config['driver'] === 'smtp' ? 'mail_test_error_smtp' : 'mail_test_error_native')];
    }

    /** Réglages de l'envoi (expéditeur de l'Owner compris), tels que MailerService les applique aux vrais envois. */
    private function loadConfig(): array
    {
        return $this->mailer->summary();
    }
    private function phpIniInfo(): array
    {
        return [
            'SMTP'          => ini_get('SMTP')          ?: __('not_defined'),
            'smtp_port'     => ini_get('smtp_port')     ?: __('not_defined'),
            'sendmail_path' => ini_get('sendmail_path') ?: __('not_defined'),
        ];
    }
}
