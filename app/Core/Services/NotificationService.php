<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Le push et l'instantané body sont traduits dans la langue du destinataire (`users.language`, sinon celle de
 * l'instance) — même principe que l'e-mail des rapports journaliers (bundle daily-report). L'affichage dans
 * l'application, lui, retraduit body_key dans la langue courante (notification_body()).
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationRepositoryInterface $repo,
        private readonly PushNotificationService $push,
        private readonly TranslationService $translations,
        private readonly UserRepositoryInterface $users,
        // Optionnel : sans registre, aucune notification n'est rattachée à un type d'objet (pas de « lu » automatique).
        private readonly ?NotificationTypeRegistry $types = null,
        // Optionnel : sans préférences, le push part toujours (comportement d'avant les préférences).
        private readonly ?NotificationPreferenceService $preferences = null,
        // Optionnel : sans lui, pas de web push (le push mobile FCM, lui, passe par $push).
        private readonly ?WebPushService $webPush = null,
        // Optionnel : sans lui, pas d'e-mail.
        private readonly ?NotificationMailer $mailer = null,
    ) {}

    /**
     * @param string|null $link Chemin relatif de l'app (ex. '/employee/shifts/day?start=2026-08-03')
     *                          vers lequel la notification renvoie au clic — voir NotificationController::open().
     *                          null si cette notification n'a pas de destination pertinente (comportement
     *                          par défaut inchangé : simple redirection vers /notifications).
     */
    public function notify(int $userId, string $type, string $bodyKey, array $replace = [], ?int $referenceId = null, ?string $link = null): void
    {
        // Le lien vient du code appelant (souvent d'un bundle) mais finit dans la cloche, les toasts, le push et l'e-mail :
        // un chemin qui n'est pas interne (autre origine, « /\ »…) est écarté ici, une seule fois, pour tous les canaux.
        $link = $link !== null ? (safe_redirect_path($link, '') ?: null) : null;

        // Paramètres réduits à des chaînes : ils sont stockés en JSON pour retraduire plus tard, avec le même
        // résultat qu'aujourd'hui.
        $params = array_map(fn($v) => is_scalar($v) || $v === null ? (string) $v : '', $replace);

        $locale   = $this->resolveLocale($userId);
        $previous = $this->translations->getLocale();
        $this->translations->setLocale($locale);

        try {
            $title = $this->translations->translate('notif_' . $type);
            $body  = $this->translations->translate($bodyKey, $params);
        } finally {
            $this->translations->setLocale($previous);
        }

        // body_key + params : le texte est retraduit à l'affichage dans la langue courante du lecteur (voir
        // notification_body()). body n'est qu'un instantané de repli, pour une clé devenue introuvable (ex. bundle
        // désactivé depuis) : on garde au moins le texte dans la langue du destinataire au moment de l'envoi.
        $saved = $this->repo->save([
            'user_id'      => $userId,
            'type'         => $type,
            'body_key'     => $bodyKey,
            'params'       => $params,
            'reference_type' => $referenceId !== null ? $this->types?->get($type)?->referenceType : null,
            'reference_id' => $referenceId,
            'body'         => $body,
            'link'         => $link,
            'is_read'      => 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        // Best-effort : une notification en base ne doit jamais échouer parce que
        // l'envoi push (appel réseau externe vers FCM) a un problème. PushNotificationService
        // avale déjà ses propres erreurs (voir sa docblock) ; ceinture-bretelles ici.
        // La cloche (ci-dessus) est toujours alimentée ; le push respecte la préférence du type. Pendant les heures
        // silencieuses de l'utilisateur il est quand même envoyé, mais marqué « quiet » : le navigateur ou l'appareil
        // l'affiche sans son ni vibration. Rien n'est retenu, donc rien à rejouer (pas de cron requis).
        $notificationId = (int) ($saved['id'] ?? 0);
        $pushTitle      = $title === 'notif_' . $type ? 'Kintai' : $title;

        // Un canal en panne ou refusé par l'utilisateur ne gêne jamais les autres : un bloc chacun.
        try {
            if ($this->channelEnabled($userId, $type, 'push')) {
                $this->sendPush($userId, $type, $pushTitle, $body, $referenceId, $link, $notificationId, $this->isQuiet($userId, $type));
            }
        } catch (\Throwable) {
        }

        // E-mail : préférence du type seulement, envoi immédiat. Un e-mail ne réveille personne : les heures silencieuses
        // ne le retiennent pas. NotificationMailer ne lève
        // jamais ; le try/catch n'est qu'une ceinture-bretelles.
        try {
            if ($this->mailer !== null && $this->channelEnabled($userId, $type, 'email')) {
                $user = $this->users->findById($userId);
                if ($user !== null) {
                    $this->mailer->send($user, $type, $pushTitle, $body, $notificationId);
                }
            }
        } catch (\Throwable) {
        }
    }

    private function sendPush(int $userId, string $type, string $pushTitle, string $body, ?int $referenceId, ?string $link, int $notificationId, bool $quiet = false): void
    {
        $pushData = self::pushPayload($type, $referenceId, $link, $notificationId, $quiet);
        // Mobile (FCM) puis navigateurs (web push natif) : le canal « push » des préférences couvre les deux.
        $this->push->sendToUser($userId, $pushTitle, $body, $pushData);
        $this->webPush?->sendToUser($userId, $pushTitle, $body, $pushData);
    }

    /**
     * Données applicatives jointes au push (lues par le service worker et l'application mobile).
     *
     * @return array<string, string>
     */
    public static function pushPayload(string $type, ?int $referenceId, ?string $link, int $notificationId, bool $quiet): array
    {
        $data = [
            'type'            => $type,
            'reference_id'    => (string) ($referenceId ?? ''),
            'link'            => $link ?? '',
            // Permet au clic sur la notification système (service worker) de réutiliser
            // le même mécanisme "marquer lu + rediriger" que le dropdown web — voir
            // NotificationController::open() et le notificationclick de sw.js.tpl.
            'notification_id' => (string) $notificationId,
        ];
        if ($quiet) {
            // Heures silencieuses : le service worker affiche sans son ni vibration, FCM passe en priorité basse.
            $data['quiet'] = '1';
        }

        return $data;
    }

    /** @param int[] $userIds */
    public function notifyMany(array $userIds, string $type, string $bodyKey, array $replace = [], ?int $referenceId = null, ?string $link = null): void
    {
        // Préférences de tous les destinataires en une requête.
        $this->preferences?->preload($userIds);
        $this->webPush?->preload($userIds);
        foreach (array_unique($userIds) as $uid) {
            $this->notify((int) $uid, $type, $bodyKey, $replace, $referenceId, $link);
        }
    }

    /**
     * « Lu » automatique : l'utilisateur vient de voir l'objet (shift, congé, échange…) désigné par ses notifications
     * non lues. Ne lève jamais : une panne ici ne doit pas empêcher d'afficher la page.
     *
     * @param int|list<int> $referenceId identifiant(s) de l'objet, tel que passé à notify()
     */
    public function markReadFor(int $userId, string $referenceType, int|array $referenceId): void
    {
        try {
            $this->repo->markReadByReference($userId, $referenceType, array_values(array_map('intval', (array) $referenceId)));
        } catch (\Throwable $e) {
            Log::warning('notification_mark_read_failed', ['user_id' => $userId, 'reference_type' => $referenceType, 'error' => $e->getMessage()]);
        }
    }

    /** Canal externe activé par l'utilisateur pour ce type (la notification de test de l'Owner passe toujours). */
    private function channelEnabled(int $userId, string $type, string $channel): bool
    {
        if ($this->preferences === null || $type === NotificationPreferenceService::TEST_TYPE) {
            return true;
        }
        return $this->preferences->isEnabled($userId, $type, $channel);
    }

    /** L'utilisateur est dans ses heures silencieuses : le push part sans son ni vibration. Jamais pour la notification de test. */
    private function isQuiet(int $userId, string $type): bool
    {
        return $this->preferences !== null && $type !== NotificationPreferenceService::TEST_TYPE
            && $this->preferences->isQuietNow($userId);
    }

    private function resolveLocale(int $userId): string
    {
        // Langue du profil du destinataire, sinon celle de l'instance.
        return user_locale($this->users->findById($userId));
    }
}
