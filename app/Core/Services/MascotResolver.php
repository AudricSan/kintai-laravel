<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Résout quelle mascotte (kitsune/tanuki) afficher pour la requête en cours
 * et le chemin d'une pose donnée. Le tirage en mode "mix" est mémoïsé — un
 * seul par requête, quel que soit le nombre d'appels — et n'est jamais
 * persisté (pas de session/cookie, un rechargement retire au sort).
 * Toujours prêt à fonctionner même sans aucun asset tanuki sur le disque :
 * `path()` retombe silencieusement sur kitsune si le fichier de la
 * mascotte active n'existe pas pour ce contexte.
 */
final class MascotResolver
{
    private ?string $picked = null;

    public function __construct(private readonly AppSettingsService $settings)
    {
    }

    public function active(): string
    {
        if ($this->picked === null) {
            $mode = $this->settings->mascotMode();
            $this->picked = $mode === 'mix'
                ? (random_int(0, 1) === 0 ? 'kitsune' : 'tanuki')
                : $mode;
        }

        return $this->picked;
    }

    /** Chemin relatif depuis public/assets/img/ (ex. "mascot/kitsune/login.png"). */
    public function path(string $context): string
    {
        $candidate = "mascot/{$this->active()}/{$context}.png";
        if (file_exists(BASE_PATH . '/public/assets/img/' . $candidate)) {
            return $candidate;
        }

        return "mascot/kitsune/{$context}.png";
    }
}
