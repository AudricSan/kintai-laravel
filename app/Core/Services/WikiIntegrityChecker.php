<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Contrôle d'un dossier de wiki (clone de Kintai.wiki.git ou miroir `.wiki/`) avant publication :
 *  - chaque page de _Sidebar.md existe dans les trois langues (anglais à la racine, `fr/`, `ja/`) ;
 *  - chaque page porte la ligne de choix de langue ;
 *  - tout lien interne (page ou image) pointe vers un fichier qui existe ;
 *  - signale les pages qui ne figurent pas dans la barre latérale (hors pages-relais d'anciens liens).
 *
 * Ne modifie rien. Voir `php scripts/check-wiki.php <dossier>`.
 */
final class WikiIntegrityChecker
{
    /** Une page de moins de lignes non vides que ceci est un relais d'ancien lien : pas listée dans la barre latérale. */
    private const STUB_MAX_LINES = 6;

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(string $wikiPath): array
    {
        $errors   = [];
        $warnings = [];
        $wikiPath = rtrim(str_replace('\\', '/', $wikiPath), '/');

        $sidebarFile = $wikiPath . '/_Sidebar.md';
        if (!is_file($sidebarFile)) {
            return ['errors' => ['_Sidebar.md introuvable'], 'warnings' => []];
        }

        $groups = WikiContentService::parseSidebarMarkdown((string) file_get_contents($sidebarFile));
        $listed = [];
        foreach ($groups as $slugs) {
            foreach ($slugs as $slug) {
                $listed[$slug] = true;
            }
        }

        // Wiki à plat : toutes les pages sont à la racine. Chaque page déclare sa langue et les noms de ses versions
        // dans sa ligne de langue ; on en tire, par clé de page (nom anglais), le fichier de chaque langue.
        $pages = []; // nom de fichier => ['key' => clé, 'lang' => langue, 'content' => texte]
        foreach (glob("$wikiPath/*.md") ?: [] as $file) {
            $name = basename($file, '.md');
            if ($name[0] === '_') {
                continue;
            }
            $content = str_replace("\r\n", "\n", (string) file_get_contents($file));
            $line    = WikiContentService::languageLine($content);
            $lang    = $line['own'] ?? 'en';
            $key     = $lang === 'en' ? $name : ($line['links']['en'] ?? $name);
            $pages[$name] = ['key' => $key, 'lang' => $lang, 'content' => $content, 'links' => $line['links']];
        }

        // Fichier attendu de chaque langue pour chaque page anglaise, d'après sa ligne de langue.
        $expected = [];
        foreach ($pages as $name => $page) {
            if ($page['lang'] === 'en') {
                $expected[$name] = ['en' => $name, 'fr' => $page['links']['fr'] ?? $name, 'ja' => $page['links']['ja'] ?? $name];
            }
        }

        foreach (array_keys($listed) as $slug) {
            if (!isset($pages[$slug])) {
                $errors[] = "page manquante (en) : $slug";
                continue;
            }
            foreach (['fr', 'ja'] as $lang) {
                $file = $expected[$slug][$lang];
                if (!isset($pages[$file])) {
                    $errors[] = "page manquante ($lang) : $slug" . ($file !== $slug ? " ($file)" : '');
                }
            }
        }

        $translatedNames = [];
        foreach ($expected as $names) {
            $translatedNames[$names['fr']] = $translatedNames[$names['ja']] = true;
        }

        foreach ($pages as $name => $page) {
            $content = $page['content'];

            if (!str_contains($content, '🌐')) {
                $errors[] = "ligne de choix de langue absente : $name";
            }
            if ($page['lang'] !== 'en' && !isset($expected[$page['key']])) {
                $errors[] = "page traduite sans page anglaise ({$page['lang']}) : $name";
            }
            if ($page['lang'] === 'en' && !isset($listed[$name]) && $this->nonEmptyLines($content) > self::STUB_MAX_LINES) {
                $warnings[] = "page absente de la barre latérale (en) : $name";
            }
            foreach ($this->brokenLinks($wikiPath, $content) as $target) {
                $errors[] = "lien cassé dans $name : $target";
            }
        }

        // Une page anglaise qui annonce une traduction absente (hors barre latérale).
        foreach ($expected as $slug => $names) {
            foreach (['fr', 'ja'] as $lang) {
                if (!isset($listed[$slug]) && !isset($pages[$names[$lang]])) {
                    $errors[] = "traduction manquante ($lang) : $slug";
                }
            }
        }
        sort($errors);
        sort($warnings);
        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }

    private function nonEmptyLines(string $content): int
    {
        return count(array_filter(explode("\n", $content), static fn(string $l) => trim($l) !== ''));
    }
    /** @return list<string> cibles de liens internes qui ne correspondent à aucun fichier (le wiki est à plat) */
    private function brokenLinks(string $wikiPath, string $content): array
    {
        // Les blocs de code ne contiennent pas de liens (exemples de syntaxe, adresses).
        $content = (string) preg_replace('/```.*?```/s', '', $content);
        $content = (string) preg_replace('/`[^`\n]*`/', '', $content);

        $broken = [];
        if (preg_match_all('/\]\(([^)\s]+)\)/', $content, $m) === false) {
            return [];
        }
        foreach ($m[1] as $href) {
            if (preg_match('#^(https?:|mailto:|tel:|//)#i', $href) === 1 || $href[0] === '#') {
                continue;
            }
            $path = preg_replace('/[#?].*$/', '', $href);
            if ($path === '' || $path === null) {
                continue;
            }
            $target = rawurldecode(ltrim($path, '/'));
            // Lien absolu du wiki (/Compte/Depot/wiki/Page) : on ne garde que le nom de la page.
            if (str_contains($target, '/wiki/')) {
                $target = substr($target, (int) strrpos($target, '/wiki/') + 6);
            }
            $hasExt = pathinfo($target, PATHINFO_EXTENSION) !== '';
            if (!is_file("$wikiPath/" . ($hasExt ? $target : $target . '.md'))) {
                $broken[] = $href;
            }
        }
        return array_values(array_unique($broken));
    }
}
