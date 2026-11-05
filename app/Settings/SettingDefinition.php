<?php

namespace App\Settings;

use Closure;

/**
 * Déclaration d'un réglage : clé, type, défaut, bornes, chiffrement. Le Core et les bundles les enregistrent dans
 * `AppSettings` ; sans ligne en base, la valeur vient de `config` (si renseignée), sinon du défaut.
 */
final class SettingDefinition
{
    public const TYPE_STRING = 'string';

    public const TYPE_INT = 'int';

    public const TYPE_BOOL = 'bool';

    /**
     * @param  string  $key  Clé en minuscules, espace de noms avant le point (`mail.from_address`).
     * @param  string  $type  `string`, `int` ou `bool`.
     * @param  mixed  $default  Valeur par défaut, ou fonction qui la calcule.
     * @param  int|null  $min  Borne basse d'un entier (appliquée à l'écriture).
     * @param  int|null  $max  Borne haute d'un entier (appliquée à l'écriture).
     * @param  bool  $encrypted  Stockée chiffrée en base (secrets).
     * @param  string|null  $config  Clé de configuration qui sert de repli (env d'installation) avant `$default`.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = self::TYPE_STRING,
        public readonly mixed $default = null,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly bool $encrypted = false,
        public readonly ?string $config = null,
    ) {}

    /**
     * Valeur utilisée quand la base n'a rien pour cette clé : la configuration si elle est renseignée, sinon le défaut.
     */
    public function fallback(): mixed
    {
        if ($this->config !== null) {
            $value = config($this->config);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $this->defaultValue();
    }

    public function defaultValue(): mixed
    {
        return $this->default instanceof Closure ? ($this->default)() : $this->default;
    }
}
