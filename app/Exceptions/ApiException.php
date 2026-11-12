<?php

namespace App\Exceptions;

use Kintai\Contracts\Api\ApiException as ContractApiException;

/**
 * Erreur de l'API avec son code stable (`{"error": ..., "code": ...}`). Le message est déjà traduit dans la langue de la requête.
 * Même classe que celle du contrat, que les bundles lèvent : le rendu des erreurs ne connaît que celle du contrat.
 */
class ApiException extends ContractApiException {}
