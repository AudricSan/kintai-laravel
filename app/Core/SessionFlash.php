<?php

declare(strict_types=1);

namespace kintai\Core;

/**
 * Messages à afficher une seule fois, après une redirection, transportés par la session.
 *
 * Pour les textes libres (message d'erreur d'une installation de bundle, d'une sauvegarde…), qui passaient
 * auparavant dans l'URL (`?error=<texte>`) : n'importe qui pouvait alors fabriquer un lien affichant le texte de
 * son choix dans un bandeau Kintai authentique. L'URL ne porte plus qu'un code ; le texte reste côté serveur.
 * Le layout de l'application affiche puis efface ces messages (layout/app.php).
 */
final class SessionFlash
{
    private const SESSION_KEY = '_kintai_flash';
    // Variantes stylées par components/alerts.css.
    private const TYPES = ['success', 'danger', 'warning'];

    public static function put(string $type, string $text): void
    {
        if (trim($text) === '') {
            return;
        }
        $_SESSION[self::SESSION_KEY][] = [
            'type' => in_array($type, self::TYPES, true) ? $type : 'warning',
            'text' => $text,
        ];
    }

    /**
     * Messages en attente, retirés de la session (affichés une seule fois).
     *
     * @return list<array{type: string, text: string}>
     */
    public static function pull(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);

        return is_array($messages) ? array_values(array_filter(
            $messages,
            static fn($m): bool => is_array($m) && is_string($m['type'] ?? null) && is_string($m['text'] ?? null)
        )) : [];
    }
}
