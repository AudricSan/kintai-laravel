<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Contrôle de l'adresse (« endpoint ») d'un abonnement web push avant de l'enregistrer.
 *
 * Le serveur enverra des requêtes POST à cette adresse : sans contrôle, un utilisateur pourrait y mettre une adresse
 * interne (réseau local, métadonnées d'un hébergeur) et faire émettre des requêtes depuis le serveur. Les services de
 * push réels (FCM, Mozilla, Apple, Windows…) sont tous en HTTPS sur un nom de domaine public ; on refuse donc le
 * reste : autre schéma, identifiants dans l'adresse, port inhabituel, adresse IP littérale, nom local, et tout nom
 * qui se résout vers une adresse privée ou réservée, en IPv4 comme en IPv6 (un nom peut avoir les deux : il suffit d'une
 * seule adresse interne pour le refuser).
 */
final class WebPushEndpointValidator
{
    private const MAX_LENGTH = 2000;

    /** @var (callable(string): list<string>)|null Résolution DNS injectable pour les tests */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public function isAcceptable(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_LENGTH) {
            return false;
        }
        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (!str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if ($host === 'localhost' || preg_match('/\.(localhost|local|internal|lan|home|test|invalid|example)$/', $host) === 1) {
            return false;
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Adresse publique routable. Au-delà des plages privées et réservées de PHP, on refuse les préfixes IPv6 qui
     * embarquent une adresse IPv4 (NAT64, 6to4, Teredo) : ils permettraient de viser une adresse interne sous une forme
     * que le filtre ne reconnaît pas, et aucun service de push ne s'y trouve.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return true;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ([
            ['64:ff9b::', 96],       // NAT64 (RFC 6052)
            ['64:ff9b:1::', 48],     // NAT64 à usage local (RFC 8215)
            ['2002::', 16],          // 6to4
            ['2001::', 32],          // Teredo
            ['2001:db8::', 32],      // documentation
        ] as [$prefix, $bits]) {
            if (self::inPrefix($packed, (string) inet_pton($prefix), $bits)) {
                return false;
            }
        }
        return true;
    }

    private static function inPrefix(string $packed, string $prefix, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($prefix, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($packed[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask);
    }

    /** @return list<string> adresses IPv4 et IPv6 du nom (enregistrements A et AAAA) */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
        if ($ips !== []) {
            return $ips;
        }
        // Résolveur système en secours (dns_get_record peut échouer là où le système sait résoudre) : IPv4 seulement.
        $fallback = @gethostbynamel($host);
        return $fallback === false ? [] : $fallback;
    }
}
