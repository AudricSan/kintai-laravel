<?php

declare(strict_types=1);

namespace kintai\Http\Middleware;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use kintai\Core\Request as KintaiRequest;
use kintai\Core\Response as KintaiResponse;
use kintai\Http\KintaiBridge;
use kintai\Http\KintaiHttp;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Premier middleware de toute route Kintai dans la pile de Laravel (alias « kintai.kernel ») : crée la requête Kintai
 * que partagent les étages suivants, rend les exceptions qui en remontent (Application::handleException()), tient le
 * journal d'accès, puis convertit la réponse Kintai en réponse Symfony pour Laravel.
 */
final class KintaiKernel
{
    /** Attribut de la requête Laravel qui porte la requête Kintai en cours. */
    public const REQUEST = 'kintai.request';

    public function __construct(private readonly Application $laravel) {}

    public function handle(IlluminateRequest $request, Closure $next): SymfonyResponse
    {
        $app = KintaiHttp::app($this->laravel);
        $kintai = new KintaiRequest();
        $kintai->linkNative($request);
        $request->attributes->set(self::REQUEST, $kintai);

        return KintaiBridge::toSymfony($app->handleWith($kintai, function (KintaiRequest $kintai) use ($request, $next): KintaiResponse {
            $request->attributes->set(self::REQUEST, $kintai);
            return CarriedResponse::unwrap($next($request));
        }));
    }

    /** La requête Kintai en cours (posée par handle()). */
    public static function request(IlluminateRequest $request): KintaiRequest
    {
        return $request->attributes->get(self::REQUEST);
    }
}
