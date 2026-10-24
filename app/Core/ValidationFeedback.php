<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\Exceptions\ValidationException;

/**
 * Erreur de validation d'un formulaire web (audit n°8, I6, décision : retour au formulaire). Avant, une
 * ValidationException levée par un contrôleur web menait à une page 422 générique, sans retour au formulaire, et le
 * message précis (« vous n'avez aucun magasin », « URL du magasin invalide »…) était souvent perdu.
 *
 * Pour une soumission (POST, PUT, PATCH, DELETE) venue d'une page du site : retour sur cette page, avec les messages
 * précis en bandeau (SessionFlash) et la saisie conservée (OldInput). Sinon (pas de page d'origine connue), la page
 * 422 affiche au moins les messages précis.
 */
final class ValidationFeedback
{
    public static function redirectBack(ValidationException $e, Request $request): ?Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }
        $back = self::originPage($request);
        if ($back === null) {
            return null;
        }
        foreach (self::messages($e) as $text) {
            SessionFlash::put('danger', $text);
        }
        OldInput::put(base_url() . $request->uri(), $request->allPost());

        return Response::redirect($back, 303);
    }

    /**
     * Page qui a envoyé le formulaire (Referer), seulement si elle appartient au site. Contrairement à back_url(), la
     * page courante est acceptée : un formulaire est souvent envoyé à l'adresse même de sa page (GET affiche, POST
     * enregistre), et c'est bien là qu'il faut revenir.
     */
    private static function originPage(Request $request): ?string
    {
        $referer = (string) ($request->header('Referer') ?? '');
        $host    = (string) ($request->server('HTTP_HOST') ?? '');
        if ($referer === '' || $host === '') {
            return null;
        }
        $origin = ($request->isSecure() ? 'https://' : 'http://') . $host;
        return $referer === $origin || str_starts_with($referer, $origin . '/') ? $referer : null;
    }

    /**
     * Messages précis de l'exception (un par champ, sans doublon), ou son message général à défaut.
     *
     * @return list<string>
     */
    public static function messages(ValidationException $e): array
    {
        $messages = [];
        $errors   = $e->errors;
        array_walk_recursive($errors, static function ($message) use (&$messages): void {
            if (is_string($message) && trim($message) !== '') {
                $messages[] = $message;
            }
        });
        $messages = array_values(array_unique($messages));
        return $messages !== [] ? $messages : [$e->getMessage()];
    }
}
