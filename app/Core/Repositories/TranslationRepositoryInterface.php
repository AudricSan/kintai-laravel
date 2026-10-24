<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface TranslationRepositoryInterface
{
    /** @return array<string,string> key => value pour cette langue. */
    public function findByLocale(string $locale): array;

    public function findValue(string $locale, string $key): ?string;

    /** @return string[] Union des clés présentes dans toutes les langues. */
    public function findAllKeys(): array;

    public function save(string $locale, string $key, string $value): void;

    /** Supprime une clé pour une langue. Retourne 1 si supprimée, 0 sinon. */
    public function delete(string $locale, string $key): int;

    public function countByLocale(string $locale): int;
}
