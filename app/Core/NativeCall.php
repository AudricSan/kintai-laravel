<?php

declare(strict_types=1);

namespace kintai\Core;

use Illuminate\Http\Request as IlluminateRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Passerelle de migration : appelle un contrôleur ou un middleware écrit pour Laravel (Illuminate\Http\Request en
 * premier paramètre) depuis l'ancienne pile Kintai, ou l'inverse. Disparaît avec la dernière classe convertie.
 */
final class NativeCall
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /** La méthode attend-elle la requête de Laravel plutôt que celle de Kintai ? */
    public static function acceptsNativeRequest(object $target, string $method): bool
    {
        $key = $target::class . '::' . $method;
        if (!isset(self::$cache[$key])) {
            $type = (new ReflectionMethod($target, $method))->getParameters()[0] ?? null;
            $named = $type?->getType();
            self::$cache[$key] = $named instanceof ReflectionNamedType
                && is_a($named->getName(), IlluminateRequest::class, true);
        }

        return self::$cache[$key];
    }

    /** La requête de Laravel de cette demande Kintai (ou une copie des globales si elle n'est pas reliée). */
    public static function nativeRequest(Request $request): IlluminateRequest
    {
        return $request->nativeRequest() ?? IlluminateRequest::capture();
    }

    /** Ce que renvoie un code natif, vu de la pile Kintai. */
    public static function response(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if ($result instanceof SymfonyResponse) {
            return Response::native($result);
        }

        throw new \LogicException('Une action doit renvoyer une réponse, pas ' . get_debug_type($result) . '.');
    }
}
