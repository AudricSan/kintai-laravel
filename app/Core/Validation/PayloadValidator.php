<?php

declare(strict_types=1);

namespace kintai\Core\Validation;

use kintai\Core\Exceptions\ValidationException;

/**
 * Validation des corps JSON de l'API : liste blanche, types, bornes.
 *
 * Avant l'audit du 13/10/2026 (M9), les contrôleurs passaient le corps tel quel au dépôt : un champ
 * inconnu ou mal typé finissait en erreur SQL (500), et n'importe quelle colonne pouvait être écrite
 * (dates de création, empreinte de mot de passe…).
 *
 * Règles d'un champ (toutes facultatives sauf « type ») :
 *   type     string | text | int | number | bool | date | time | email | color | enum | id | list
 *   required le champ doit être présent et non vide à la création ; en mise à jour partielle, il peut
 *            manquer mais pas être vidé
 *   nullable null (ou chaîne vide) accepté et enregistré comme null
 *   max      longueur maximale (string, text, email, list) ou valeur maximale (int, number)
 *   min      valeur minimale (int, number)
 *   in       valeurs permises (enum)
 *   pattern  expression régulière que doit respecter une chaîne
 *
 * Les champs absents des règles sont ignorés (jamais écrits). Les valeurs renvoyées sont normalisées :
 * chaînes rognées, entiers, décimaux, booléens en 0/1, heures en HH:MM.
 */
final class PayloadValidator
{
    /**
     * @param array<string, mixed>                $data
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, bool $partial = false): array
    {
        $clean  = [];
        $errors = [];

        foreach ($rules as $field => $rule) {
            $present = array_key_exists($field, $data);
            $value   = $present ? $data[$field] : null;
            $empty   = $value === null || (is_string($value) && trim($value) === '') || $value === [];

            if (!$present) {
                if (!$partial && !empty($rule['required'])) {
                    $errors[$field] = __('val_field_required', ['field' => $field]);
                }
                continue;
            }

            if ($empty) {
                if (!empty($rule['required'])) {
                    $errors[$field] = __('val_field_required', ['field' => $field]);
                } elseif (!empty($rule['nullable'])) {
                    $clean[$field] = ($rule['type'] ?? '') === 'list' ? [] : null;
                } else {
                    $errors[$field] = __('val_field_invalid', ['field' => $field]);
                }
                continue;
            }

            $error = null;
            $normalized = self::normalize($value, $rule, $error);
            if ($error !== null) {
                $errors[$field] = __($error, ['field' => $field, 'max' => (string) ($rule['max'] ?? ''), 'min' => (string) ($rule['min'] ?? '')]);
                continue;
            }
            $clean[$field] = $normalized;
        }

        if ($errors !== []) {
            throw new ValidationException($errors, reset($errors));
        }

        return $clean;
    }

    /**
     * Mise à jour : comme validate() en mode partiel, mais refuse un corps qui ne modifie rien
     * (un PUT {} était accepté et renvoyait 200 sans rien faire).
     *
     * @param array<string, mixed>                $data
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    public static function validateUpdate(array $data, array $rules): array
    {
        $clean = self::validate($data, $rules, true);
        if ($clean === []) {
            throw new ValidationException(['body' => __('val_no_updatable_field')], __('val_no_updatable_field'));
        }
        return $clean;
    }

    /** @param array<string, mixed> $rule */
    private static function normalize(mixed $value, array $rule, ?string &$error): mixed
    {
        $type = (string) ($rule['type'] ?? 'string');

        switch ($type) {
            case 'string':
            case 'text':
            case 'email':
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    $error = 'val_field_invalid';
                    return null;
                }
                $s = trim((string) $value);
                if (isset($rule['max']) && mb_strlen($s) > (int) $rule['max']) {
                    $error = 'val_field_too_long';
                    return null;
                }
                if ($type === 'email' && filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'val_field_invalid';
                    return null;
                }
                // Une ligne (string) ne contient ni retour à la ligne ni caractère de contrôle.
                if ($type !== 'text' && preg_match('/[\x00-\x1F\x7F]/', $s) === 1) {
                    $error = 'val_field_invalid';
                    return null;
                }
                if (isset($rule['pattern']) && preg_match((string) $rule['pattern'], $s) !== 1) {
                    $error = 'val_field_invalid';
                    return null;
                }
                return $s;

            case 'int':
            case 'id':
                $i = is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT);
                if ($i === false || ($type === 'id' && $i <= 0)) {
                    $error = 'val_field_invalid';
                    return null;
                }
                return self::inRange($i, $rule, $error) ? $i : null;

            case 'number':
                if (is_bool($value) || !is_numeric($value)) {
                    $error = 'val_field_invalid';
                    return null;
                }
                $f = (float) $value;
                if (!is_finite($f)) {
                    $error = 'val_field_invalid';
                    return null;
                }
                return self::inRange($f, $rule, $error) ? $f : null;

            case 'bool':
                if (in_array($value, [true, 1, '1', 'true'], true)) {
                    return 1;
                }
                if (in_array($value, [false, 0, '0', 'false'], true)) {
                    return 0;
                }
                $error = 'val_field_invalid';
                return null;

            case 'date':
                if (!is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1
                    || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $error = 'val_field_invalid';
                    return null;
                }
                return $value;

            case 'time':
                if (!is_string($value) || preg_match('/\A([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\z/', $value, $m) !== 1) {
                    $error = 'val_field_invalid';
                    return null;
                }
                return $m[1] . ':' . $m[2];

            case 'color':
                // Même format que ColorRule (#RGB / #RRGGBB) ; vérifié ici pour ne pas dépendre de son arrivée.
                if (!is_string($value) || preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $value) !== 1) {
                    $error = 'val_field_invalid';
                    return null;
                }
                return $value;

            case 'enum':
                $allowed = (array) ($rule['in'] ?? []);
                foreach ($allowed as $candidate) {
                    if ((string) $candidate === (is_scalar($value) ? (string) $value : null)) {
                        return $candidate;
                    }
                }
                $error = 'val_field_invalid';
                return null;

            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    $error = 'val_field_invalid';
                    return null;
                }
                foreach ($value as $item) {
                    if (!is_string($item) || $item === '' || mb_strlen($item) > 100) {
                        $error = 'val_field_invalid';
                        return null;
                    }
                }
                if (isset($rule['max']) && count($value) > (int) $rule['max']) {
                    $error = 'val_field_too_long';
                    return null;
                }
                return array_values(array_unique($value));
        }

        $error = 'val_field_invalid';
        return null;
    }

    /** @param array<string, mixed> $rule */
    private static function inRange(int|float $n, array $rule, ?string &$error): bool
    {
        if ((isset($rule['min']) && $n < $rule['min']) || (isset($rule['max']) && $n > $rule['max'])) {
            $error = 'val_field_out_of_range';
            return false;
        }
        return true;
    }
}
