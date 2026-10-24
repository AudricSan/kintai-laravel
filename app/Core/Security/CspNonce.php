<?php

declare(strict_types=1);

namespace kintai\Core\Security;

/**
 * Nonce de la Content-Security-Policy, propre à la requête en cours.
 *
 * `script-src 'self' 'nonce-…'` remplace `'unsafe-inline'` : seul un `<script nonce="…">` écrit par
 * une vue s'exécute, ce qui neutralise l'impact d'un XSS (un `<script>` ou un `onclick=` injecté n'a pas
 * le nonce). Même chose pour `style-src` : seul un `<style nonce="…">` s'applique, un `style="…"` injecté
 * (fausse fenêtre posée sur la page, contenu masqué) est ignoré. La valeur est tirée une fois par requête, imprévisible, et n'est jamais réutilisée : la
 * réutiliser d'une requête à l'autre permettrait à un attaquant de la lire puis de l'injecter.
 *
 * SecurityHeadersMiddleware appelle renew() à chaque requête ; les vues la lisent via csp_nonce().
 */
final class CspNonce
{
    private static ?string $value = null;

    /** Nonce de la requête courante (généré à la demande si renew() n'a pas encore été appelé). */
    public static function get(): string
    {
        return self::$value ??= base64_encode(random_bytes(16));
    }

    /** Démarre une nouvelle requête : l'ancien nonce n'est plus valable. */
    public static function renew(): string
    {
        self::$value = null;

        return self::get();
    }
}
