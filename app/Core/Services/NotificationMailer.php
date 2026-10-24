<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Mail\MailerService;

/**
 * Canal e-mail des notifications : un message par notification, envoyé tout de suite (pas de file d'attente), avec un
 * bouton « Voir » vers la notification (connexion requise) et un lien de désabonnement en un clic pour ce type.
 *
 * Meilleur effort : ne lève jamais, un échec est journalisé. Sans e-mail configuré sur l'instance ou sans URL
 * publique (les liens d'un e-mail doivent être absolus), n'envoie rien.
 */
final class NotificationMailer
{
    /** Délai de connexion SMTP : l'envoi se fait pendant la requête de l'utilisateur qui déclenche la notification. */
    private const SMTP_TIMEOUT = 5;

    public function __construct(
        private readonly MailerService $mailer,
        private readonly PublicUrlResolver $publicUrl,
        private readonly UnsubscribeTokens $tokens,
        private readonly TranslationService $translations,
    ) {}

    /** L'envoi d'e-mail est-il possible sur cette instance (e-mail réglé et URL publique connue) ? */
    public function isAvailable(): bool
    {
        return $this->mailer->isConfigured() && $this->publicUrl->resolve() !== null;
    }

    /**
     * @param array  $user           utilisateur destinataire (id, email, langue…)
     * @param string $type           type de notification
     * @param string $title          libellé du type, déjà traduit dans la langue du destinataire
     * @param string $body           texte de la notification, déjà traduit de même
     * @param int    $notificationId notification en base : le bouton « Voir » passe par /notifications/{id}/open
     */
    public function send(array $user, string $type, string $title, string $body, int $notificationId): bool
    {
        try {
            $email = trim((string) ($user['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
            $base = $this->publicUrl->resolve();
            if ($base === null || !$this->mailer->isConfigured()) {
                return false;
            }

            $userId = (int) ($user['id'] ?? 0);
            $open   = $base . '/notifications/' . $notificationId . '/open';
            $unsub  = $base . '/notifications/unsubscribe?' . http_build_query([
                'u' => $userId,
                't' => $type,
                'c' => 'email',
                's' => $this->tokens->sign($userId, $type, 'email'),
            ]);

            $locale   = user_locale($user);
            $previous = $this->translations->getLocale();
            $this->translations->setLocale($locale);
            try {
                $html = $this->html($locale, $title, $body, $open, $unsub);
            } finally {
                $this->translations->setLocale($previous);
            }

            $sent = $this->mailer->send(
                [$email],
                '[Kintai] ' . $title,
                $html,
                [],
                [
                    'List-Unsubscribe'      => '<' . $unsub . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
                self::SMTP_TIMEOUT,
            );
            if (!$sent) {
                Log::warning('notification_email_failed', ['user_id' => $userId, 'type' => $type]);
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('notification_email_error', ['user_id' => $user['id'] ?? null, 'type' => $type, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function html(string $locale, string $title, string $body, string $openUrl, string $unsubscribeUrl): string
    {
        $h      = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $lang   = $h($locale);
        $title  = $h($title);
        $body   = nl2br($h($body), false);
        $open   = $h($openUrl);
        $unsub  = $h($unsubscribeUrl);
        $view   = $h($this->translations->translate('notif_mail_view'));
        $footer = $h($this->translations->translate('notif_mail_footer'));
        $stop   = $h($this->translations->translate('notif_mail_unsubscribe'));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head><meta charset="UTF-8"></head>
        <body style="font-family:sans-serif;background:#f8fafc;margin:0;padding:32px 0;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center">
              <table width="520" cellpadding="0" cellspacing="0"
                     style="background:#fff;border-radius:8px;border:1px solid #e2e8f0;padding:40px;">
                <tr><td>
                  <h1 style="font-size:22px;color:#1e293b;margin:0 0 8px;">Kintai</h1>
                  <h2 style="font-size:16px;color:#475569;font-weight:normal;margin:0 0 24px;">{$title}</h2>
                  <p style="color:#334155;line-height:1.6;">{$body}</p>
                  <p style="text-align:center;margin:32px 0;">
                    <a href="{$open}"
                       style="background:#2563eb;color:#fff;text-decoration:none;
                              padding:12px 28px;border-radius:6px;font-size:15px;font-weight:600;">
                      {$view}
                    </a>
                  </p>
                  <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
                  <p style="color:#94a3b8;font-size:12px;line-height:1.6;">
                    {$footer}<br>
                    <a href="{$unsub}" style="color:#94a3b8;">{$stop}</a>
                  </p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
