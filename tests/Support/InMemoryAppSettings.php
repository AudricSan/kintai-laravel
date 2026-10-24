<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/** Réglages de l'instance gardés en mémoire, pour les tests qui n'ont pas besoin de base. */
final class InMemoryAppSettings implements AppSettingsRepositoryInterface
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = []) {}

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function setMany(array $settings): void
    {
        $this->values = $settings + $this->values;
    }
}
