<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fichier envoyé en flux (sans le charger en mémoire), avec la méthode header() fluide des réponses de Laravel.
 */
final class FileStreamResponse extends StreamedResponse
{
    public function header(string $key, string $value): static
    {
        $this->headers->set($key, $value);

        return $this;
    }
}
