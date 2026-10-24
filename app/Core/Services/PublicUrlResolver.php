<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * URL publique de l'instance (schéma + domaine + éventuel sous-dossier), pour les liens absolus envoyés hors de
 * l'application — e-mail de réinitialisation du mot de passe en premier lieu.
 *
 * Ordre : variable d'environnement APP_URL, puis réglage Owner `app_public_url` (renseigné par l'installateur
 * web, modifiable dans /admin/owner-settings). Volontairement **jamais** l'en-tête Host de la requête en cours :
 * celui qui demande une réinitialisation choisit cet en-tête, il pourrait donc faire envoyer à une victime un
 * lien pointant vers son propre domaine et y récupérer le jeton (empoisonnement d'en-tête Host).
 *
 * Aucune URL connue → null : mieux vaut ne pas envoyer l'e-mail (et le journaliser) que d'envoyer un lien
 * relatif, qu'aucun client mail ne sait ouvrir.
 */
final class PublicUrlResolver
{
    public const SETTING_KEY = 'app_public_url';

    /** @param string|null $envUrl valeur de APP_URL ; null = lue dans l'environnement */
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly ?string $envUrl = null,
    ) {
    }

    /** URL publique normalisée (sans « / » final), ou null si aucune n'est configurée ou valide. */
    public function resolve(): ?string
    {
        return self::normalize($this->envValue()) ?? self::normalize($this->settings->get(self::SETTING_KEY));
    }

    /** Vrai quand APP_URL est défini et valide : il prime alors sur le réglage Owner. */
    public function isForcedByEnvironment(): bool
    {
        return self::normalize($this->envValue()) !== null;
    }

    /**
     * Valide et normalise une URL de base : http(s) uniquement, domaine obligatoire, ni identifiants, ni requête,
     * ni fragment. Renvoie null pour toute autre valeur.
     */
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 255) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:.]+\]$/i', $parts['host']) !== 1) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme . '://' . strtolower($parts['host']) . $port . $path;
    }

    private function envValue(): string
    {
        return $this->envUrl ?? (string) env('APP_URL', '');
    }
}
