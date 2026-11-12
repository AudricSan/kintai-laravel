<?php

namespace Kintai\Contracts\Settings;

/**
 * Réglages de l'instance (stockés en base, modifiables par l'Owner). Le Core et les bundles déclarent les leurs par
 * une `SettingDefinition` ; une clé non déclarée est refusée. Sans ligne en base, la valeur vient de la configuration
 * (si la définition en désigne une), sinon du défaut.
 */
interface Settings
{
    public function register(SettingDefinition ...$definitions): void;

    public function has(string $key): bool;

    public function get(string $key): mixed;

    public function string(string $key): string;

    public function int(string $key): int;

    public function bool(string $key): bool;

    public function set(string $key, mixed $value): void;
}
