<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;

/**
 * Erreur HTTP prévue (404, 403, 422…) : l'HttpException de Symfony, celle de abort(), avec les sous-classes nommées
 * du contrat des bundles (NotFoundException, ForbiddenException…).
 */
class HttpException extends SymfonyHttpException
{
    /** @param array<string, string> $headers */
    public function __construct(int $statusCode, string $message = '', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($statusCode, $message, $previous, $headers, $statusCode);
    }
}