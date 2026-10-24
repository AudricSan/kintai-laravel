<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Mail\NativeTransport;
use kintai\Core\Mail\SmtpTransport;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\UI\ViewRenderer;

final class MailTestController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly AppSettingsService $settings,
    ) {}

    public function show(Request $request): Response
    {
        return Response::html($this->view->render('system.mail-test', [
            'title'      => __('mailtest_title'),
            'mailConfig' => $this->loadConfig(),
            'phpIni'     => $this->phpIniInfo(),
            'result'     => null,
            'last_to'    => '',
        ], 'layout.app'));
    }

    public function send(Request $request): Response
    {
        $to = trim((string) $request->post('to', ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result = ['success' => false, 'error' => __('mail_test_error_invalid_email')];
        } else {
            $result = $this->attemptSend($to);
        }

        return Response::html($this->view->render('system.mail-test', [
            'title'      => __('mailtest_title'),
            'mailConfig' => $this->loadConfig(),
            'phpIni'     => $this->phpIniInfo(),
            'result'     => $result,
            'last_to'    => $to,
        ], 'layout.app'));
    }

    private function attemptSend(string $to): array
    {
        $config      = $this->loadConfig();
        $fromAddress = $config['from']['address'] ?? 'noreply@example.com';
        $fromName    = $config['from']['name']    ?? 'Kintai';
        $subject     = __('mail_test_subject', ['date' => date('Y-m-d H:i:s')]);
        $body        = '<p>' . __('mail_test_body_intro') . '</p>'
                     . '<p><small>' . __('mail_test_body_sent_at', [
                         'date'   => date('d/m/Y à H:i:s'),
                         'driver' => '<strong>' . htmlspecialchars($config['driver'] ?? 'native') . '</strong>',
                     ]) . '</small></p>';

        if (($config['driver'] ?? 'native') === 'smtp') {
            $smtp      = $config['smtp'] ?? [];
            $transport = new SmtpTransport(
                host:       (string) ($smtp['host']       ?? 'localhost'),
                port:       (int)    ($smtp['port']       ?? 587),
                username:   (string) ($smtp['username']   ?? ''),
                password:   (string) ($smtp['password']   ?? ''),
                encryption: (string) ($smtp['encryption'] ?? 'tls'),
            );

            $ok = $transport->send($fromAddress, $fromName, [$to], $subject, $body);

            if ($ok) {
                return ['success' => true];
            }

            $err = $transport->getLastError() ?? __('mail_test_error_smtp');
            return ['success' => false, 'error' => $err];
        }

        // Driver natif (mail())
        error_clear_last();
        $transport = new NativeTransport();
        $ok        = $transport->send($fromAddress, $fromName, [$to], $subject, $body);

        if ($ok) {
            return ['success' => true];
        }

        $err = error_get_last()['message'] ?? __('mail_test_error_native');
        return ['success' => false, 'error' => $err];
    }

    private function loadConfig(): array
    {
        $file = defined('BASE_PATH') ? BASE_PATH . '/config/mail.php' : '';
        // Même expéditeur que les vrais envois : celui réglé par l'Owner, sinon celui du fichier.
        return $this->settings->mailConfig(($file !== '' && file_exists($file)) ? (require $file) : []);
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
