<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lecture d'un fichier de configuration PHP « données seulement » (`<?php return [...];`) sans exécuter de code
 * (audit SEC-05) : le contenu est lu une seule fois, chaque jeton est contrôlé (chaînes, nombres, true/false/null,
 * tableaux, return), et seulement alors évalué. Variable, appel de fonction, constante magique, instruction ou
 * interpolation : le fichier est refusé.
 */
final class LiteralConfig
{
    private const ALLOWED_TOKENS = [
        T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_RETURN, T_ARRAY,
        T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW,
    ];

    private const ALLOWED_CHARS = ['(', ')', '[', ']', ',', ';', '-', '+'];

    /** @return array<mixed>|null null si le fichier n'est pas une simple liste de valeurs littérales */
    public static function parse(string $php): ?array
    {
        $tokens = token_get_all($php);
        $code   = '';
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (!in_array($token, self::ALLOWED_CHARS, true)) {
                    return null;
                }
                $code .= $token;
                continue;
            }
            [$id, $text] = $token;
            if ($id === T_STRING) {
                if (!in_array(strtolower($text), ['true', 'false', 'null'], true)) {
                    return null;
                }
            } elseif (!in_array($id, self::ALLOWED_TOKENS, true)) {
                return null;
            }
            $code .= $id === T_OPEN_TAG ? '' : $text;
        }

        try {
            $value = eval($code); // jetons contrôlés ci-dessus : uniquement des littéraux
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }
}
