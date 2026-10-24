<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Container;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Security\AttemptCounter;
use kintai\Core\Services\AppSettingsService;

/**
 * Limitation de débit de toute l'API (/api/*), y compris les routes des bundles : au plus N requêtes par
 * minute et par jeton (réglage Owner « api_rate_limit_per_minute », 300 par défaut, 0 = désactivé).
 * Sans jeton Bearer, la limite s'applique par IP. Chaque IP a en plus un plafond de IP_FACTOR fois la limite,
 * tous jetons confondus : sans lui, un script qui change de jeton (même invalide) à chaque requête obtiendrait
 * un compteur neuf à chaque fois. Le facteur laisse passer les employés d'un même magasin, qui partagent une IP.
 *
 * Middleware global plutôt que de route : les bundles déclarent leurs propres routes d'API et ne pourraient
 * pas être couverts autrement. Il passe avant ApiAuthMiddleware, donc compte aussi les requêtes au jeton
 * invalide (refusées ensuite en 401). La connexion garde en plus sa propre limitation des échecs
 * (LoginThrottleMiddleware).
 */
final class ApiRateLimitMiddleware implements MiddlewareInterface
{
    public const SETTING_KEY = 'api_rate_limit_per_minute';
    public const DEFAULT_PER_MINUTE = 300;
    public const MAX_PER_MINUTE = 100000;
    public const IP_FACTOR = 10;
    private const WINDOW = 60;

    private readonly AttemptCounter $counter;

    public function __construct(
        private readonly Container $container,
        ?AttemptCounter $counter = null,
    ) {
        $this->counter = $counter ?? new AttemptCounter();
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!str_starts_with($request->uri(), '/api/')) {
            return $next($request);
        }

        $limit = $this->limit();
        if ($limit === 0) {
            return $next($request);
        }

        $key   = 'api:' . $this->client($request);
        $ipKey = 'api-ip:' . $request->ip();

        $retryAfter = max(
            $this->counter->retryAfter($key, $limit, self::WINDOW),
            $this->counter->retryAfter($ipKey, $limit * self::IP_FACTOR, self::WINDOW),
        );
        if ($retryAfter > 0) {
            throw new HttpException(429, __('error_too_many_requests'), [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $used = $this->counter->hit($key, self::WINDOW);
        $this->counter->hit($ipKey, self::WINDOW);

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $limit - $used));
    }

    /** Requêtes autorisées par minute ; 0 = pas de limite. Valeur par défaut si les réglages sont illisibles. */
    private function limit(): int
    {
        try {
            $value = $this->container->make(AppSettingsService::class)->apiRateLimitPerMinute();
        } catch (\Throwable) {
            $value = self::DEFAULT_PER_MINUTE;
        }

        return max(0, min(self::MAX_PER_MINUTE, $value));
    }

    /** Identifie le client : empreinte du jeton Bearer (jamais le jeton en clair), sinon l'IP. */
    private function client(Request $request): string
    {
        $header = (string) ($request->header('Authorization') ?? '');
        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
            if ($token !== '') {
                return 'token:' . hash_token($token);
            }
        }

        return 'ip:' . $request->ip();
    }
}
