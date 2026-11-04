<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Erreur de l'API avec son code stable (`{"error": ..., "code": ...}`). Le message est déjà traduit dans la langue de la requête.
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(int $status, public readonly string $errorCode, string $message, array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
