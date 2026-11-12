<?php

namespace App\Update;

use Carbon\CarbonImmutable;

/**
 * Release GitHub téléchargeable : sa version, ses notes et les adresses de l'archive et de sa somme de contrôle.
 */
final class Release
{
    public function __construct(
        public readonly Version $version,
        public readonly string $tag,
        public readonly string $notes,
        public readonly string $zipUrl,
        public readonly int $zipSize,
        public readonly string $checksumUrl,
        public readonly ?CarbonImmutable $publishedAt,
    ) {}
}
