<?php

namespace Kintai\Bundles\GoogleCalendar;

/**
 * État d'une liaison OAuth en cours. Le `state` (anti-CSRF) et le vérificateur PKCE restent côté serveur, dans la session
 * de la personne qui a lancé la liaison : valables 10 minutes et à usage unique. Un retour de Google ouvert depuis une
 * autre session, expiré ou rejoué est refusé.
 */
final class OAuthState
{
    private const KEY = 'google-calendar-oauth';

    public const TTL_SECONDS = 600;

    /**
     * Ouvre une liaison : l'état attendu au retour de Google et le vérificateur PKCE.
     *
     * @return array{state: string, verifier: string}
     */
    public static function start(string $nameMode, ?int $storeId): array
    {
        $state = bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        session()->put(self::KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'at' => time(),
            'name_mode' => $nameMode,
            'store_id' => $storeId,
        ]);

        return ['state' => $state, 'verifier' => $verifier];
    }

    /**
     * Reprend (et oublie) la liaison en cours si `$state` est celui attendu et n'a pas expiré.
     *
     * @return array{verifier: string, name_mode: string, store_id: int|null}|null
     */
    public static function claim(?string $state): ?array
    {
        $pending = session()->pull(self::KEY);

        if (! is_array($pending) || $state === null || $state === ''
            || time() - (int) ($pending['at'] ?? 0) > self::TTL_SECONDS
            || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return null;
        }

        return [
            'verifier' => (string) $pending['verifier'],
            'name_mode' => (string) ($pending['name_mode'] ?? 'instance'),
            'store_id' => isset($pending['store_id']) ? (int) $pending['store_id'] : null,
        ];
    }
}
