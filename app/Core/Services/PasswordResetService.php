<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\PasswordResetRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

final class PasswordResetService
{
    private const TTL_HOURS = 1;

    public function __construct(
        private readonly PasswordResetRepositoryInterface $resets,
        private readonly UserRepositoryInterface $users,
        private readonly MailerService $mailer,
        // Optionnel pour les tests qui construisent le service à la main.
        private readonly ?CredentialRevoker $revoker = null,
        // Pour écrire l'e-mail dans la langue du destinataire ; sans lui, langue de la requête en cours.
        private readonly ?TranslationService $translator = null,
    ) {}

    /**
     * Génère un token et envoie le lien par mail.
     * Retourne toujours true même si l'email n'existe pas (anti-énumération).
     */
    public function sendResetLink(string $email, string $baseUrl): bool
    {
        // Le lien part par e-mail : il doit être absolu (voir PublicUrlResolver). Un chemin seul (« /Kintai »)
        // donnerait un lien qu'aucun client mail ne sait ouvrir — on n'envoie rien plutôt qu'un lien cassé.
        if (self::resetLink($baseUrl, 'x') === null) {
            Log::error('password_reset_no_public_url', ['reason' => "URL publique de l'instance non configurée (APP_URL ou réglage Owner)"]);
            return false;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || empty($user['is_active']) || !empty($user['deleted_at'])) {
            return true;
        }

        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600);

        $this->resets->create($email, hash_token($token), $expiresAt);

        $name = trim(($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        $link = (string) self::resetLink($baseUrl, $token);

        // L'e-mail est écrit dans la langue du destinataire (sa préférence), pas celle de la personne qui l'a demandé.
        [$subject, $body] = $this->inLocale(
            user_locale($user),
            fn(): array => [__('reset_mail_subject'), $this->buildMailBody($name, $link)],
        );

        return $this->mailer->send([$email], $subject, $body);
    }

    /**
     * Lien absolu de réinitialisation pour ce jeton, ou null si $baseUrl n'est pas une URL absolue valide
     * (voir PublicUrlResolver::normalize()).
     */
    public static function resetLink(string $baseUrl, string $token): ?string
    {
        $publicUrl = PublicUrlResolver::normalize($baseUrl);

        return $publicUrl === null ? null : $publicUrl . '/reset-password/' . rawurlencode($token);
    }

    /**
     * Vérifie qu'un token est valide et non expiré.
     * Retourne l'enregistrement ou null si invalide.
     */
    public function findValidToken(string $token): ?array
    {
        $record = $this->resets->findByToken(hash_token($token));

        if ($record === null) {
            return null;
        }

        if (($record['expires_at'] ?? '') < date('Y-m-d H:i:s')) {
            return null;
        }

        return $record;
    }

    /**
     * Réinitialise le mot de passe et invalide le token.
     */
    public function reset(string $token, string $newPassword): bool
    {
        $record = $this->findValidToken($token);

        if ($record === null) {
            return false;
        }

        $user = $this->users->findByEmail($record['email']);

        if ($user === null || empty($user['is_active'])) {
            return false;
        }

        $this->users->save(array_merge($user, [
            'password_hash' => \kintai\Core\Auth\PasswordHasher::hash($newPassword),
        ]));

        $this->resets->deleteByEmail($record['email']);
        // Un cookie « rester connecté » ou un jeton d'API volé ne doit pas survivre à la réinitialisation.
        // Les sessions déjà ouvertes tombent d'elles-mêmes (l'empreinte du mot de passe a changé).
        $this->revoker?->revokeAllFor((int) $user['id']);

        return true;
    }

    /**
     * Exécute $fn avec la langue $locale (si le traducteur est disponible), puis rétablit la langue de la requête.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function inLocale(?string $locale, callable $fn): mixed
    {
        if ($this->translator === null || $locale === null || $locale === '') {
            return $fn();
        }
        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);
        try {
            return $fn();
        } finally {
            $this->translator->setLocale($previous);
        }
    }

    private function buildMailBody(string $name, string $link): string
    {
        $e = static fn(string $key, array $replace = []): string => htmlspecialchars(__($key, $replace), ENT_QUOTES);
        $escapedLink = htmlspecialchars($link, ENT_QUOTES);
        $lang        = htmlspecialchars($this->translator?->getLocale() ?? 'en', ENT_QUOTES);
        $title       = $e('reset_mail_title');
        $greeting    = $e('reset_mail_greeting', ['name' => $name]);
        $intro       = $e('reset_mail_intro');
        $button      = $e('reset_mail_button');
        $expiry      = $e('reset_mail_expiry');
        $ignore      = $e('reset_mail_ignore');

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
                  <h2 style="font-size:16px;color:#475569;font-weight:normal;margin:0 0 24px;">
                    {$title}
                  </h2>
                  <p style="color:#334155;line-height:1.6;">{$greeting}</p>
                  <p style="color:#334155;line-height:1.6;">
                    {$intro}
                  </p>
                  <p style="text-align:center;margin:32px 0;">
                    <a href="{$escapedLink}"
                       style="background:#2563eb;color:#fff;text-decoration:none;
                              padding:12px 28px;border-radius:6px;font-size:15px;font-weight:600;">
                      {$button}
                    </a>
                  </p>
                  <p style="color:#64748b;font-size:13px;line-height:1.6;">
                    {$expiry}<br>
                    {$ignore}
                  </p>
                  <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
                  <p style="color:#94a3b8;font-size:12px;">Kintai — Shift Management</p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
