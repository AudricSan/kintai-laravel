<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Security\AttemptCounter;

/**
 * Freine la recherche de mots de passe sur les routes de connexion (web et API).
 *
 * L'ancien limiteur comptait CHAQUE requête par IP (5 par 5 min), succès compris : dans un magasin,
 * où tous les employés partagent une IP publique, les connexions du début de service étaient
 * bloquées, alors qu'aucune limite ne visait un compte précis — un attaquant qui change d'IP n'était
 * pas freiné, or le mot de passe par défaut est « 0000 ».
 *
 * Ici seuls les ÉCHECS comptent, sur deux clés indépendantes :
 *  - par IP (large, car partagée dans un magasin) ;
 *  - par compte visé (e-mail, ou code employé + code magasin), quelle que soit l'IP.
 * Une connexion réussie remet à zéro le compteur du compte. Le contrôleur signale un échec en
 * posant l'attribut de requête `auth_failed` ; le middleware ne devine rien à partir de la réponse.
 *
 * Contrepartie assumée : quiconque connaît un identifiant peut verrouiller ce compte pendant la
 * fenêtre en enchaînant les échecs. C'est le prix d'une limite par compte ; elle expire seule.
 */
final class LoginThrottleMiddleware implements MiddlewareInterface
{
    public const IP_MAX_FAILURES = 20;
    public const ACCOUNT_MAX_FAILURES = 10;
    public const WINDOW = 900; // 15 minutes

    public function __construct(private readonly AttemptCounter $counter = new AttemptCounter()) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ipKey      = 'login:ip:' . $request->ip();
        $identifier = $this->identifier($request);
        $accountKey = $identifier !== null ? 'login:account:' . $identifier : null;

        $retryAfter = max(
            $this->counter->retryAfter($ipKey, self::IP_MAX_FAILURES, self::WINDOW),
            $accountKey !== null ? $this->counter->retryAfter($accountKey, self::ACCOUNT_MAX_FAILURES, self::WINDOW) : 0,
        );
        if ($retryAfter > 0) {
            throw new HttpException(429, __('error_too_many_attempts'), ['Retry-After' => (string) $retryAfter]);
        }

        $response = $next($request);

        if ($request->getAttribute('auth_failed') === true) {
            $this->counter->hit($ipKey, self::WINDOW);
            if ($accountKey !== null) {
                $this->counter->hit($accountKey, self::WINDOW);
            }
        } elseif ($accountKey !== null && $response->status() < 400) {
            // Connexion réussie : on repart de zéro pour ce compte (le compteur d'IP, lui, reste).
            $this->counter->clear($accountKey);
        }

        return $response;
    }

    /**
     * Compte visé par la tentative, normalisé (insensible à la casse et aux espaces), ou null si la
     * requête n'en désigne aucun. Web : champs de formulaire ; API : corps JSON.
     */
    private function identifier(Request $request): ?string
    {
        $json = $request->json();
        $json = is_array($json) ? $json : [];
        $field = static fn(string $name): string => trim((string) ($request->post($name) ?? $json[$name] ?? ''));

        $mode = $field('login_mode');
        $email = $field('email');
        $employeeCode = $field('employee_code');
        $storeCode = $field('store_code');

        if (($mode === 'code' || $email === '') && $employeeCode !== '' && $storeCode !== '') {
            return 'code:' . strtoupper($storeCode) . ':' . strtoupper($employeeCode);
        }
        if ($email !== '') {
            return 'email:' . strtolower($email);
        }
        return null;
    }
}
