<?php

namespace App\Support;

/**
 * Dépôt GitHub au format « compte/dépôt », tel que saisi dans la configuration (documentation, signalements).
 */
final class GithubRepository
{
    public static function isValid(string $repository): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) === 1;
    }
}
