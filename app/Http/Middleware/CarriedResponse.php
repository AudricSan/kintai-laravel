<?php

declare(strict_types=1);

namespace kintai\Http\Middleware;

use kintai\Core\Response as KintaiResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Ce qui circule entre les middlewares Kintai dans la pile de Laravel : la réponse Kintai elle-même, ou l'exception
 * qui remonte. Laravel attend une réponse Symfony de chaque étage, et rend toute exception qui traverse sa pile en
 * page d'erreur dès l'étage où elle survient ; Kintai, lui, la laisse remonter à travers ses middlewares jusqu'à
 * Application::handleException(). On la transporte donc ici, et chaque middleware Kintai la voit remonter de son
 * $next comme avant. Seul KintaiKernel, le premier étage, en fait une vraie réponse.
 */
final class CarriedResponse extends SymfonyResponse
{
    private function __construct(
        public readonly ?KintaiResponse $kintai,
        public readonly ?Throwable $thrown,
    ) {
        parent::__construct();
    }

    public static function of(KintaiResponse $response): self
    {
        return new self($response, null);
    }

    public static function thrown(Throwable $e): self
    {
        return new self(null, $e);
    }

    /**
     * Ce que l'étage suivant a produit, vu par un middleware Kintai : sa réponse, ou son exception relancée. Une
     * réponse produite par un middleware de Laravel ajouté à la route est reprise telle quelle.
     */
    public static function unwrap(SymfonyResponse $response): KintaiResponse
    {
        if ($response instanceof self) {
            if ($response->thrown !== null) {
                throw $response->thrown;
            }
            // En-têtes posés par un middleware natif sur le transporteur : repris sur la réponse Kintai.
            foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
                if (!in_array(strtolower($name), ['cache-control', 'date'], true)) {
                    $response->kintai->withHeader($name, implode(', ', $values));
                }
            }
            return $response->kintai;
        }

        return KintaiResponse::native($response);
    }
}
