<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Garde des requêtes sortantes du serveur (audit SEC-08, risque SSRF) : une adresse n'est suivie que si elle est en
 * HTTPS, sans identifiants, et si son nom de domaine ne désigne que des adresses publiques (ni boucle locale, ni réseau
 * privé, ni lien local, ni plage réservée comme les métadonnées cloud 169.254.169.254).
 *
 * KINTAI_HTTP_ALLOW_PRIVATE=true lève ces restrictions (développement local : serveur de licence sur localhost).
 */
final class UrlGuard
{
    /**
     * @return array{host: string, ip: string, port: int}|string adresse validée (à épingler avec CURLOPT_RESOLVE), ou
     *                                                          le motif du refus
     */
    public static function check(string $url): array|string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'adresse invalide';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'identifiants dans l\'adresse';
        }
        $scheme       = strtolower($parts['scheme']);
        $allowPrivate = filter_var(env('KINTAI_HTTP_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOL);
        if ($scheme !== 'https' && !($allowPrivate && $scheme === 'http')) {
            return 'HTTPS requis';
        }

        $host = trim($parts['host'], '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips  = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($ips === []) {
            return 'nom de domaine introuvable';
        }
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return 'adresse non publique';
                }
            }
        }

        return ['host' => $host, 'ip' => $ips[0], 'port' => $port];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
