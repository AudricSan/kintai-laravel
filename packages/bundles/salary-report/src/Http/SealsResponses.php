<?php

namespace Kintai\Bundles\SalaryReport\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Les réponses qui contiennent des salaires ne sont ni mises en cache ni devinées par le navigateur.
 */
trait SealsResponses
{
    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function sealed(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
