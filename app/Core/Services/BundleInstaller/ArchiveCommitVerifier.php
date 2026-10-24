<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleInstaller;

/**
 * Vérifie qu'un zipball GitHub correspond au commit épinglé par le registry.
 *
 * Le zipball d'une release est une archive générée par GitHub (`git archive`) : elle porte l'identifiant
 * du commit de son tag, à deux endroits — le commentaire du ZIP (sha complet, 40 hex) et le nom du
 * dossier racine (`owner-repo-<sha abrégé>`). Si le tag d'un bundle a été déplacé vers un autre commit
 * (dépôt ou compte compromis), l'archive générée porte ce autre commit et ne correspond plus à celui que
 * le registry a épinglé : l'installation est refusée avant toute extraction.
 *
 * Périmètre : cela protège contre un tag déplacé ou un dépôt piégé, l'archive étant fabriquée par GitHub.
 * Ce n'est pas une signature — le transport reste protégé par HTTPS, et le registry lui-même reste le point
 * de confiance (ses changements passent par une PR relue).
 *
 * En cas de doute (aucune empreinte lisible dans l'archive), la vérification échoue : refuser est le seul
 * comportement sûr quand on ne peut pas prouver que l'archive est la bonne.
 */
final class ArchiveCommitVerifier
{
    /**
     * @return string|null null si l'archive correspond au commit attendu, sinon le message d'erreur (en français,
     *                     comme les autres erreurs de BundleInstallerService).
     */
    public static function verify(string $zipPath, string $expectedCommit): ?string
    {
        $expected = strtolower($expectedCommit);
        if (preg_match('/^[0-9a-f]{40}$/', $expected) !== 1) {
            return "Le commit épinglé par le registry n'est pas un identifiant valide : installation refusée.";
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return "Impossible d'ouvrir l'archive pour vérifier son empreinte.";
        }

        try {
            // 1. Commentaire du ZIP : le sha complet du commit (méthode fiable).
            $comment = strtolower(trim((string) $zip->getArchiveComment()));
            if (preg_match('/^[0-9a-f]{40}$/', $comment) === 1) {
                return hash_equals($expected, $comment) ? null : self::mismatch($expected, $comment);
            }

            // 2. Repli : sha abrégé dans le nom du dossier racine (owner-repo-<sha>). Moins précis (7+ hex),
            //    mais un préfixe qui ne colle pas suffit à refuser.
            $short = self::shortShaFromRootFolder($zip);
            if ($short !== null) {
                return str_starts_with($expected, $short) ? null : self::mismatch($expected, $short);
            }
        } finally {
            $zip->close();
        }

        return "Impossible de vérifier l'empreinte de l'archive (aucun commit lisible) : installation refusée par précaution.";
    }

    private static function shortShaFromRootFolder(\ZipArchive $zip): ?string
    {
        $name = $zip->getNameIndex(0);
        if (!is_string($name) || $name === '') {
            return null;
        }
        $root = explode('/', $name, 2)[0];
        if (preg_match('/-([0-9a-f]{7,40})$/', strtolower($root), $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    private static function mismatch(string $expected, string $actual): string
    {
        return sprintf(
            "L'archive téléchargée correspond au commit %s, alors que le registry épingle le commit %s pour cette version : "
            . "installation refusée. Le tag a peut-être été déplacé — ne pas installer avant d'avoir vérifié le dépôt du bundle.",
            substr($actual, 0, 12),
            substr($expected, 0, 12)
        );
    }
}
