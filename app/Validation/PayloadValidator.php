<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;
use Closure;
use Illuminate\Support\Facades\Validator;

/**
 * Validation des corps JSON de l'API : liste blanche, types, bornes. Le contrôle est celui du validateur de Laravel
 * (Validator::make) ; cette classe traduit les règles de ApiPayloadRules en règles Laravel, ne garde que les champs
 * déclarés et normalise les valeurs acceptées.
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
        $input     = [];
        $laravel   = [];
        $messages  = [];
        $nullables = [];

        foreach ($rules as $field => $rule) {
            $type = (string) ($rule['type'] ?? 'string');
            if (!array_key_exists($field, $data)) {
                if (!$partial && !empty($rule['required'])) {
                    $laravel[$field] = ['required'];
                    $messages["$field.required"] = __('val_field_required', ['field' => $field]);
                }
                continue;
            }

            $value = $data[$field];
            // Une chaîne est rognée avant le contrôle (comme le fait TrimStrings pour une requête de formulaire).
            if (is_string($value) && in_array($type, ['string', 'text', 'email'], true)) {
                $value = trim($value);
            }
            $empty = $value === null || (is_string($value) && trim($value) === '') || $value === [];

            if ($empty && empty($rule['required']) && !empty($rule['nullable'])) {
                $nullables[$field] = $type === 'list' ? [] : null;
                continue;
            }

            $input[$field]   = $value;
            $laravel[$field] = self::laravelRules($type, $rule);
            $messages        = array_merge($messages, self::messages($field, $laravel[$field], $type, $rule));
            if ($type === 'list') {
                // Chaque élément : une chaîne non vide de 100 caractères au plus.
                $laravel["$field.*"] = ['string', 'min:1', 'max:100'];
                $messages        = array_merge($messages, self::messages("$field.*", $laravel["$field.*"], 'string', []));
            }
        }

        ValidationException::check(Validator::make($input, $laravel, $messages));

        // Dans l'ordre des règles, comme le comparent les appelants.
        $clean = [];
        foreach (array_keys($rules) as $field) {
            if (array_key_exists($field, $nullables)) {
                $clean[$field] = $nullables[$field];
            } elseif (array_key_exists($field, $input)) {
                $clean[$field] = self::normalize($input[$field], (string) ($rules[$field]['type'] ?? 'string'));
            }
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

    /**
     * Règles Laravel d'un champ présent.
     *
     * @param array<string, mixed> $rule
     * @return list<mixed>
     */
    private static function laravelRules(string $type, array $rule): array
    {
        // Présent mais vide : refusé (« filled »), sauf si le champ est obligatoire (message « obligatoire »).
        $rules = [!empty($rule['required']) ? 'required' : 'filled'];
        $max   = isset($rule['max']) ? 'max:' . $rule['max'] : null;
        $min   = isset($rule['min']) ? 'min:' . $rule['min'] : null;

        switch ($type) {
            case 'string':
            case 'text':
            case 'email':
                $rules[] = 'string';
                if ($max !== null) {
                    $rules[] = $max;
                }
                if ($type === 'email') {
                    $rules[] = 'email';
                }
                // Une ligne (string) ne contient ni retour à la ligne ni caractère de contrôle.
                if ($type !== 'text') {
                    $rules[] = 'regex:/\A[^\x00-\x1F\x7F]*\z/';
                }
                if (isset($rule['pattern'])) {
                    $rules[] = 'regex:' . $rule['pattern'];
                }
                break;

            case 'int':
            case 'id':
                $rules[] = 'integer';
                // filter_var() de « integer » laisse passer true (= 1) : un booléen n'est pas un entier.
                $rules[] = static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_bool($value)) {
                        $fail('val_field_invalid')->translate(['field' => $attribute]);
                    }
                };
                if ($type === 'id') {
                    $rules[] = 'min:1';
                }
                if ($min !== null) {
                    $rules[] = $min;
                }
                if ($max !== null) {
                    $rules[] = $max;
                }
                break;

            case 'number':
                $rules[] = 'numeric';
                if ($min !== null) {
                    $rules[] = $min;
                }
                if ($max !== null) {
                    $rules[] = $max;
                }
                break;

            case 'bool':
                // true/false, 1/0 et leurs écritures en texte (« boolean » de Laravel refuse « true »).
                $rules[] = static function (string $attribute, mixed $value, Closure $fail): void {
                    if (!in_array($value, [true, 1, '1', 'true', false, 0, '0', 'false'], true)) {
                        $fail('val_field_invalid')->translate(['field' => $attribute]);
                    }
                };
                break;

            case 'date':
                $rules[] = 'date_format:Y-m-d';
                break;

            case 'time':
                $rules[] = 'date_format:H:i,H:i:s';
                break;

            case 'color':
                $rules[] = new ColorRule();
                break;

            case 'enum':
                $rules[] = 'in:' . implode(',', array_map('strval', (array) ($rule['in'] ?? [])));
                break;

            case 'list':
                $rules[] = 'array';
                $rules[] = 'list';
                if ($max !== null) {
                    $rules[] = $max;
                }
                break;

            default:
                $rules[] = 'prohibited';
        }

        return $rules;
    }

    /**
     * Messages de Kintai pour les règles d'un champ : obligatoire, trop long, hors bornes, sinon format invalide.
     *
     * @param list<mixed>          $rules
     * @param array<string, mixed> $rule
     * @return array<string, string>
     */
    private static function messages(string $field, array $rules, string $type, array $rule): array
    {
        $replace = ['field' => $field, 'max' => (string) ($rule['max'] ?? ''), 'min' => (string) ($rule['min'] ?? '')];
        $range   = in_array($type, ['int', 'id', 'number'], true);
        $out     = [];
        foreach ($rules as $one) {
            if (!is_string($one)) {
                continue; // règle objet (ColorRule) : son message vient de l'objet
            }
            $name = strtolower(explode(':', $one, 2)[0]);
            $out["$field.$name"] = match (true) {
                $name === 'required', $name === 'filled' => __('val_field_required', $replace),
                $name === 'max'                          => __($range ? 'val_field_out_of_range' : 'val_field_too_long', $replace),
                $name === 'min' && $range                => __('val_field_out_of_range', $replace),
                default                                  => __('val_field_invalid', $replace),
            };
        }

        return $out;
    }

    /** Valeur acceptée, mise sous sa forme de stockage. */
    private static function normalize(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string', 'text', 'email' => trim((string) $value),
            'int', 'id'               => (int) $value,
            'number'                  => (float) $value,
            'bool'                    => in_array($value, [true, 1, '1', 'true'], true) ? 1 : 0,
            'time'                    => substr((string) $value, 0, 5),
            'list'                    => array_values(array_unique($value)),
            default                   => $value,
        };
    }
}
