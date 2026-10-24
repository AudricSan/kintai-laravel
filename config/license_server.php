<?php

/**
 * Configuration du client de licence distant (déblocage du plan payant).
 *
 * Le plan gratuit (voir PlanLimitService) fonctionne intégralement sans réseau —
 * cette config ne sert que si l'Owner saisit une clé de licence sur /admin/license.
 * `base_url`/`api_key` identifient le produit "kintai" auprès du serveur de
 * licence (License Manager, projet séparé) ; `license_key` (par instance,
 * saisie par l'Owner) est stockée en base, pas ici — voir LicenseClientService.
 *
 * Par défaut, le serveur de licence officiel de Kintai (OFFICIAL ci-dessous) : une installation neuve peut activer
 * une licence sans rien configurer. Chaque valeur peut être remplacée dans .env (serveur de licence privé) :
 *
 *   LICENSE_SERVER_URL=https://exemple.com/LicenseManager/public/api/v1
 *   LICENSE_SERVER_API_KEY=lm_xxx...
 *   LICENSE_SERVER_PUBLIC_KEY_B64=... (cle RSA publique, voir ci-dessous)
 *
 * Une variable vide (comme dans .env.example) garde la valeur officielle. La clé API identifie le produit
 * « kintai » auprès du serveur : publique par conception (embarquée dans chaque instance), seule la clé de licence
 * saisie par l'Owner est propre à un client. Aucun appel réseau tant qu'aucune clé de licence n'est saisie.
 *
 * `public_key_pem` verifie le license_token signe renvoye par activate/validate
 * (voir LicenseTokenVerifier) : c'est ce jeton, pas les champs plats de la
 * reponse, qui fait foi pour les limites (stores/employes/bundles) une fois
 * decode — un client self-hosted qui editerait sa base locale ne peut pas se
 * fabriquer un token valide sans la cle PRIVEE, qui ne quitte jamais le
 * serveur de licence. Valeur fournie par l'operateur du serveur de licence
 * (cote License Manager : scripts/generate-signing-key.php), a copier telle
 * quelle en base64 dans LICENSE_SERVER_PUBLIC_KEY_B64.
 */

declare(strict_types=1);

// Serveur de licence officiel de Kintai. Clé API produit et clé publique : publiques par conception (voir plus haut).
$official = [
    'url'            => 'https://licence.audricrosier.be/public/api/v1',
    'api_key'        => 'lm_b8d8dd2593ec3cbad89ac232bc7106bb0c3c91a8b6570f11', // ggignore — clé produit publique, pas un secret
    'public_key_b64' => 'LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0NCk1JSUJJakFOQmdrcWhraUc5dzBCQVFFRkFBT0NBUThBTUlJQkNnS0NBUUVBcC9NY00wV0d2SERiTWVnS2FUZUcNCjJsMHJJOC9KSStGcGtVQlQ2VE55V0NTUjFPQk1GMTZoODBrRW1ReXgzTWwwVGJNcTJCb215ZmpCdDhDMVV4ZWQNCmlCSGVQVUpQN2o0N1dYb3htV1BzVE1DRXRsM2FqRVB3ME5NYmdLTnpmUzhnUW5nZ0RIaUpScEdicHVXWVhXVGwNCmxBRThuRVZvcnZ1eFFiWnZhT1gzZEgzNXVTN1p4SGdlY21vM1YwZmZsTG84eVBrRHlGbnZ1ejRxY3lDR3laOGoNCm84NDM5UU5vS0oxU3RNN3JOR1NVNjdqK0dpSHN5QzY1akNyUUdJNi9pQlo0ME1haFE3TDQwL3A0UjBSdTRwMkcNCjhJbnRTenpocUFxWTVhZXJmK0VxWkJSanhLMnNCd25IcURBWnMxWXVKNkNmQ1lOTGJlNEtQSXlqb1d6dkFNZTYNCmdRSURBUUFCDQotLS0tLUVORCBQVUJMSUMgS0VZLS0tLS0NCg==',
];
// Valeur de .env si elle est renseignée, sinon la valeur officielle.
$envOr = static fn(string $key, string $default): string => ($value = trim((string) env($key, ''))) !== '' ? $value : $default;

$publicKeyB64 = $envOr('LICENSE_SERVER_PUBLIC_KEY_B64', $official['public_key_b64']);

return [
    'base_url'             => rtrim($envOr('LICENSE_SERVER_URL', $official['url']), '/'),
    'api_key'              => $envOr('LICENSE_SERVER_API_KEY', $official['api_key']),
    'check_interval_hours' => (int) env('LICENSE_SERVER_CHECK_INTERVAL_HOURS', 24),
    'grace_period_days'    => (int) env('LICENSE_SERVER_GRACE_PERIOD_DAYS', 14),
    'public_key_pem'       => $publicKeyB64 !== '' ? (base64_decode($publicKeyB64, true) ?: null) : null,
];
