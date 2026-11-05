<?php

namespace Kintai\Contracts\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Erreur de l'API avec son code stable : le Core la rend `{"error": "<message>", "code": "<code>"}` avec le statut
 * HTTP donné. Un bundle la lève pour répondre dans le format d'erreur de l'API du Core (`throw new ApiException(409,
 * 'already_decided', __('...'))`). Le message doit déjà être traduit dans la langue de la requête.
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
