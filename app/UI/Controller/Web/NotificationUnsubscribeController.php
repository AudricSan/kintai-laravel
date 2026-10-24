<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\UnsubscribeTokens;
use kintai\UI\ViewRenderer;

/**
 * Désabonnement d'un type d'e-mail de notification, depuis le lien du pied de page ou l'en-tête List-Unsubscribe.
 * Sans connexion : l'adresse est signée (UnsubscribeTokens) pour (utilisateur, type, canal) et ne permet que de couper
 * ce canal pour ce type.
 *
 *  - GET  : page de confirmation (un bouton). Un simple affichage ne désabonne jamais : les antivirus et les aperçus de
 *    messagerie ouvrent les liens sans que personne ne l'ait demandé ;
 *  - POST : désabonnement. Même adresse, ce qui est aussi ce qu'appellent les clients e-mail pour le désabonnement en
 *    un clic (RFC 8058, corps « List-Unsubscribe=One-Click ») ; pas de jeton CSRF, la signature fait foi.
 */
final class NotificationUnsubscribeController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UnsubscribeTokens $tokens,
        private readonly NotificationPreferenceService $prefs,
    ) {}

    /** GET /notifications/unsubscribe?u=&t=&c=&s= */
    public function show(Request $request): Response
    {
        $link = $this->verifiedLink($request);

        return $this->page($link === null ? 'invalid' : 'confirm', $link, $request);
    }

    /** POST /notifications/unsubscribe?u=&t=&c=&s= */
    public function confirm(Request $request): Response
    {
        $link = $this->verifiedLink($request);
        if ($link === null) {
            return $this->page('invalid', null, $request, 400);
        }

        // Type devenu inconnu (bundle désactivé) : plus aucun e-mail de ce type n'est envoyé, rien à couper.
        $this->prefs->saveChanges($link['user'], [$link['type'] => ['email' => false]]);

        return $this->page('done', $link, $request);
    }

    /** @return array{user: int, type: string, channel: string, query: string}|null */
    private function verifiedLink(Request $request): ?array
    {
        $user      = (int) $request->query('u', 0);
        $type      = (string) $request->query('t', '');
        $channel   = (string) $request->query('c', '');
        $signature = (string) $request->query('s', '');

        // Seul l'e-mail se désabonne par lien ; la signature est vérifiée sur ce que l'adresse affirme.
        if ($channel !== 'email' || !$this->tokens->verify($user, $type, $channel, $signature)) {
            return null;
        }

        return ['user' => $user, 'type' => $type, 'channel' => $channel, 'query' => http_build_query(['u' => $user, 't' => $type, 'c' => $channel, 's' => $signature])];
    }

    /** @param array{user: int, type: string, channel: string, query: string}|null $link */
    private function page(string $state, ?array $link, Request $request, int $status = 200): Response
    {
        $response = Response::html($this->view->render('auth.notification-unsubscribe', [
            'title'      => __('notif_unsub_title'),
            'state'      => $state,
            'type_label' => $link !== null ? __('notif_' . $link['type']) : '',
            'action'     => $link !== null ? route_url('notifications.unsubscribe.confirm') . '?' . $link['query'] : '',
        ], 'layout.guest'));

        return $status === 200 ? $response : $response->withStatus($status);
    }
}
