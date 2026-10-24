<?php

declare(strict_types=1);

namespace kintai\Core;

/**
 * Saisie d'un formulaire refusé, gardée en session le temps de la redirection vers ce formulaire (audit n°8, I6 :
 * avant, une erreur de validation menait à une page 422 sans retour, et tout ce qui avait été tapé était perdu).
 * Le layout la place dans un bloc JSON que public/assets/js/modules/old-input.js recopie dans le formulaire.
 *
 * Jamais conservés : jeton CSRF, méthode simulée, mots de passe et jetons, fichiers. Valeurs aplaties avec les noms
 * de champs du navigateur (« a[] », « a[b] »).
 */
final class OldInput
{
    private const SESSION_KEY = '_kintai_old_input';
    private const MAX_FIELDS  = 200;
    private const MAX_LENGTH  = 20000;

    /** @param array<string, mixed> $post */
    public static function put(string $action, array $post): void
    {
        $fields = [];
        foreach ($post as $name => $value) {
            self::flatten((string) $name, $value, $fields);
        }
        $_SESSION[self::SESSION_KEY] = ['action' => $action, 'fields' => array_slice($fields, 0, self::MAX_FIELDS, true)];
    }

    /**
     * Saisie en attente, retirée de la session (utilisée une seule fois).
     *
     * @return array{action: string, fields: array<string, string|list<string>>}|null
     */
    public static function pull(): ?array
    {
        $old = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        return is_array($old) && isset($old['action'], $old['fields']) ? $old : null;
    }

    /** @param array<string, string|list<string>> $out */
    private static function flatten(string $name, mixed $value, array &$out): void
    {
        if (self::isSecret($name)) {
            return;
        }
        if (!is_array($value)) {
            if (is_scalar($value)) {
                $out[$name] = mb_substr((string) $value, 0, self::MAX_LENGTH);
            }
            return;
        }
        if (array_is_list($value) && array_filter($value, 'is_array') === []) {
            $out[$name . '[]'] = array_map(static fn($v): string => mb_substr((string) $v, 0, self::MAX_LENGTH), $value);
            return;
        }
        foreach ($value as $key => $sub) {
            self::flatten($name . '[' . $key . ']', $sub, $out);
        }
    }

    private static function isSecret(string $name): bool
    {
        $lower = strtolower($name);
        return in_array($lower, ['_token', '_method'], true)
            || str_contains($lower, 'password')
            || str_contains($lower, 'token')
            || str_contains($lower, 'secret');
    }
}
