<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

/**
 * Fabrique et valide les segments d'URL lisibles (alias de magasin).
 *
 * Aucune translittération : les noms de magasins sont en japonais, et une romanisation automatique
 * (ICU « Any-Latin ») lirait les kanji en mandarin. L'alias automatique garde donc le nom tel quel
 * (所沢東町店 → 所沢東町店, encodé en %XX dans l'URL) ; un slug romaji propre se saisit à la main.
 */
final class SlugGenerator
{
    public const MAX_LENGTH = 60;

    /** Format d'un slug saisi à la main : minuscules ASCII, chiffres, tirets simples. */
    public const MANUAL_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Segments réservés connus avant même que les routes soient chargées (migration de remplissage).
     * À l'exécution, RouteSlugService y ajoute tous les segments littéraux du routeur.
     */
    public const BASE_RESERVED = ['create', 'edit', 'delete', 'export', 'import', 'new', 'stats', 'members', 'id'];

    /**
     * Alias automatique tiré d'un nom : caractères Unicode conservés, lettres en minuscules, blancs
     * et ponctuation remplacés par des tirets, rien qui casse une URL. Peut renvoyer '' (nom vide ou
     * composé uniquement de ponctuation) : à l'appelant de prévoir un repli.
     */
    public static function fromName(string $name): string
    {
        $slug = mb_strtolower(trim($name), 'UTF-8');
        // Tout ce qui n'est ni lettre ni chiffre (blancs, ponctuation, / ? # % \, contrôles) devient un tiret.
        $slug = (string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $slug);
        $slug = trim($slug, '-');

        if (mb_strlen($slug, 'UTF-8') > self::MAX_LENGTH) {
            $slug = rtrim(mb_substr($slug, 0, self::MAX_LENGTH, 'UTF-8'), '-');
        }

        // Un alias uniquement numérique se confondrait avec un ancien lien par identifiant.
        if ($slug !== '' && ctype_digit($slug)) {
            $slug = 'store-' . $slug;
        }

        return $slug;
    }

    /** Un slug saisi à la main est-il acceptable (format, longueur, au moins une lettre) ? */
    public static function isValidManual(string $slug): bool
    {
        return strlen($slug) <= self::MAX_LENGTH
            && preg_match(self::MANUAL_PATTERN, $slug) === 1
            && !ctype_digit(str_replace('-', '', $slug));
    }

    /**
     * Premier candidat libre parmi $base, $base-2, $base-3… ; $isTaken décide si un candidat est pris
     * (déjà utilisé ou réservé).
     *
     * @param callable(string): bool $isTaken
     */
    public static function firstFree(string $base, callable $isTaken): string
    {
        $candidate = $base;
        for ($n = 2; $isTaken($candidate); $n++) {
            $suffix    = '-' . $n;
            $candidate = mb_substr($base, 0, self::MAX_LENGTH - strlen($suffix), 'UTF-8') . $suffix;
        }

        return $candidate;
    }
}
