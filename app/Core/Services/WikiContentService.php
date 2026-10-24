<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Exceptions\NotFoundException;

/**
 * Rend en HTML les pages du wiki GitHub (clone local .wiki/, gitignoré) pour
 * une consultation directe dans l'application.
 *
 * Structure attendue (celle du wiki GitHub réel du projet) : GitHub aplatit les sous-dossiers d'un wiki, donc toutes
 * les pages sont à la racine, avec un nom de fichier propre à chaque langue :
 *   .wiki/Home.md, .wiki/Installation.md, ...       (anglais)
 *   .wiki/Accueil.md, .wiki/ホーム.md, ...           (traductions, au nom traduit)
 *   .wiki/_Sidebar.md                               (navigation, format wiki GitHub)
 *
 * La clé d'une page (barre latérale, URL /docs/{lang}/{page}) est son nom anglais. Le nom des fichiers traduits se lit
 * dans la ligne de langue de chaque page (🌐 [English](Home) · **Français** · [日本語](ホーム)). Les liens internes
 * sont des noms de page sans extension ".md" ; la barre latérale peut les écrire en liens absolus du wiki.
 */
final class WikiContentService
{
    /** Langues du wiki (toutes les pages sont à la racine du dossier). */
    private const LANGS = ['fr', 'en', 'ja'];

    /** Libellé de langue (ligne 🌐) => code de langue. */
    private const LANGUAGE_LABELS = ['English' => 'en', 'Français' => 'fr', '日本語' => 'ja'];

    /** @var array<string, string> Libellé de groupe (tel qu'écrit dans _Sidebar.md) => clé de traduction */
    private const GROUP_LABEL_KEYS = [
        'Getting Started'        => 'docs_group_getting_started',
        'Concepts & Architecture' => 'docs_group_concepts',
        'User Guide'              => 'docs_group_user_guide',
        'Reference'                => 'docs_group_reference',
        'Employee Guide'           => 'docs_group_employee',
        'Manager Guide'            => 'docs_group_manager',
        'Administration'           => 'docs_group_admin',
        'Installation & Operations' => 'docs_group_operations',
        'Developers'               => 'docs_group_developers',
        'Project'                  => 'docs_group_project',
    ];

    /** Groupe synthétique (avant tout titre de section dans _Sidebar.md, ex: Home) */
    private const TOP_GROUP = '';

    private string $wikiPath;

    public function __construct(string $wikiPath = '')
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $this->wikiPath = $wikiPath !== '' ? $wikiPath : $basePath . '/.wiki';
    }

    /**
     * @return string[]
     */
    public static function supportedLangs(): array
    {
        return self::LANGS;
    }

    public function isConfigured(): bool
    {
        return is_dir($this->wikiPath);
    }

    /**
     * URL de la page équivalente sur le wiki GitHub en ligne, consultable même
     * quand `.wiki/` n'est pas cloné localement (dépôt configuré via la même
     * variable d'environnement que la vérification de mises à jour, puisque
     * c'est le même dépôt canonique).
     */
    public function githubWikiUrl(string $lang, ?string $slug = null): string
    {
        $repo = env('GITHUB_UPDATE_REPO', 'AudricSan/Kintai');
        $slug = ($slug !== null && self::isValidSlug($slug)) ? $slug : 'Home';

        return 'https://github.com/' . $repo . '/wiki/' . rawurlencode($this->localSlug($lang, $slug));
    }

    /**
     * Table des matières groupée (telle que définie par _Sidebar.md), avec le
     * titre, la disponibilité locale et le lien GitHub de chaque page dans la
     * langue donnée (ce dernier permet d'y accéder même si la page n'est pas
     * disponible localement).
     *
     * @return array{label: string|null, items: array{slug: string, title: string, available: bool, github_url: string}[]}[]
     */
    public function tableOfContents(string $lang): array
    {
        $toc = [];

        foreach ($this->parseSidebar() as $groupName => $slugs) {
            $items = [];
            foreach ($slugs as $slug) {
                $items[] = [
                    'slug'       => $slug,
                    'title'      => $this->pageTitle($lang, $slug),
                    'available'  => $this->pageExists($lang, $slug),
                    'github_url' => $this->githubWikiUrl($lang, $slug),
                ];
            }

            $labelKey = self::GROUP_LABEL_KEYS[$groupName] ?? null;

            $toc[] = [
                'label' => $groupName === self::TOP_GROUP
                    ? null
                    : ($labelKey !== null ? __($labelKey) : $groupName),
                'items' => $items,
            ];
        }

        return $toc;
    }

    /**
     * Première page à afficher (page d'accueil du wiki).
     */
    public function firstPage(): ?string
    {
        foreach ($this->parseSidebar() as $slugs) {
            if ($slugs !== []) {
                return $slugs[0];
            }
        }

        return null;
    }

    public function pageExists(string $lang, string $slug): bool
    {
        if (!self::isValidSlug($slug) || !in_array($lang, self::LANGS, true)) {
            return false;
        }

        return file_exists($this->resolvePath($lang, $slug));
    }

    /**
     * Rend le contenu HTML d'une page du wiki.
     *
     * @throws NotFoundException si la langue/page est inconnue ou le fichier absent
     */
    public function render(string $lang, string $slug): string
    {
        if (!$this->pageExists($lang, $slug)) {
            throw new NotFoundException(__('error_doc_page_not_found', ['slug' => $slug]));
        }

        $raw = file_get_contents($this->resolvePath($lang, $slug));

        if ($raw === false) {
            throw new NotFoundException(__('error_doc_page_unreadable', ['slug' => $slug]));
        }

        $markdown  = $this->linkifyInternalPages($this->stripLanguageSwitchLine($raw), $lang);
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(false);

        return $parsedown->text($markdown);
    }

    // -------------------------------------------------------------------------
    // Résolution de chemin / slug
    // -------------------------------------------------------------------------

    /** Chemin du fichier d'une page ; $slug est la clé anglaise, le nom du fichier est celui de la langue. */
    private function resolvePath(string $lang, string $slug): string
    {
        return $this->wikiPath . '/' . $this->localSlug($lang, $slug) . '.md';
    }

    /**
     * Slugs de pages wiki : lettres (accentuées et japonaises comprises), chiffres, tirets, underscores, jamais de
     * chemin ("/", "..") ni de page méta ("_Sidebar", "_Footer"). Partagé avec
     * WikiSyncService pour valider les slugs trouvés dans le _Sidebar.md
     * distant avant de les utiliser comme noms de fichiers locaux.
     */
    public static function isValidSlug(string $slug): bool
    {
        return $slug !== '' && $slug[0] !== '_' && preg_match('/^[\p{L}\p{N}\p{M}_-]+$/u', $slug) === 1;
    }

    // -------------------------------------------------------------------------
    // Noms de fichiers localisés (fr/ et ja/)
    // -------------------------------------------------------------------------

    /**
     * Ligne de choix de langue d'une page : sa propre langue (celle en gras) et les noms des autres versions.
     * "🌐 [English](Home) · **Français** · [日本語](ホーム)" donne own = fr, links = [en => Home, ja => ホーム].
     * Statique et pure : partagée avec WikiSyncService et WikiIntegrityChecker. Les anciens liens « fr/X » et « ../X »
     * sont acceptés ; le libellé décide de la langue.
     *
     * @return array{own: string|null, links: array<string, string>}
     */
    public static function languageLine(string $markdown): array
    {
        foreach (explode("\n", $markdown) as $line) {
            if (!str_contains($line, '🌐')) {
                continue;
            }

            $own = null;
            foreach (self::LANGUAGE_LABELS as $label => $code) {
                if (str_contains($line, '**' . $label . '**')) {
                    $own = $code;
                }
            }

            $links = [];
            if (preg_match_all('/\[([^\]]+)\]\(([^)#\s]+)\)/u', $line, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $m) {
                    $code = self::LANGUAGE_LABELS[$m[1]] ?? null;
                    $slug = rawurldecode(basename($m[2]));
                    if ($code !== null && self::isValidSlug($slug)) {
                        $links[$code] ??= $slug;
                    }
                }
            }

            return ['own' => $own, 'links' => $links];
        }

        return ['own' => null, 'links' => []];
    }

    /**
     * Noms de fichiers traduits d'une page anglaise, lus dans sa ligne de langue. Une langue absente de la ligne
     * garde le nom anglais.
     *
     * @return array<string, string> langue ('fr', 'ja') => nom de fichier sans ".md"
     */
    public static function localizedSlugsFromPage(string $markdown): array
    {
        return array_diff_key(self::languageLine($markdown)['links'], ['en' => true]);
    }
    /**
     * Nom de fichier d'une page dans une langue : celui que déclare sa version anglaise, sinon le slug lui-même.
     * Les clés de page (URL de l'aide, barre latérale) restent les slugs anglais.
     */
    private function localSlug(string $lang, string $slug): string
    {
        return $this->slugMaps()['local'][$lang][$slug] ?? $slug;
    }

    /**
     * Clé de page (slug anglais) d'un nom de fichier traduit, ou le nom lui-même s'il n'est pas connu.
     */
    private function pageKey(string $lang, string $localSlug): string
    {
        return $this->slugMaps()['key'][$lang][$localSlug] ?? $localSlug;
    }

    /** @var array{local: array<string, array<string, string>>, key: array<string, array<string, string>>}|null */
    private ?array $slugMaps = null;

    /**
     * Correspondances slug anglais <-> nom de fichier traduit, construites une fois depuis les pages anglaises.
     *
     * @return array{local: array<string, array<string, string>>, key: array<string, array<string, string>>}
     */
    private function slugMaps(): array
    {
        if ($this->slugMaps !== null) {
            return $this->slugMaps;
        }

        $maps = ['local' => [], 'key' => []];

        foreach (glob($this->wikiPath . '/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            if (!self::isValidSlug($name)) {
                continue;
            }
            $line = self::languageLine((string) file_get_contents($file));

            if ($line['own'] === 'en' || $line['own'] === null) {
                // Page anglaise : la clé est son propre nom, les liens donnent les versions traduites.
                foreach ($line['links'] as $lang => $local) {
                    if ($lang !== 'en') {
                        $maps['local'][$lang][$name] = $local;
                        $maps['key'][$lang][$local]  = $name;
                    }
                }
            } elseif (isset($line['links']['en'])) {
                // Page traduite : son lien « English » donne la clé.
                $maps['local'][$line['own']][$line['links']['en']] = $name;
                $maps['key'][$line['own']][$name]                  = $line['links']['en'];
            }
        }

        return $this->slugMaps = $maps;
    }

    // -------------------------------------------------------------------------
    // _Sidebar.md → structure groupée de slugs
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string[]> Nom de groupe ("" pour le sommet) => slugs
     */
    private function parseSidebar(): array
    {
        $path = $this->wikiPath . '/_Sidebar.md';

        if (!file_exists($path)) {
            return [];
        }

        return self::parseSidebarMarkdown((string) file_get_contents($path));
    }

    /**
     * Analyse le contenu Markdown d'un `_Sidebar.md` (format wiki GitHub) en
     * groupes de slugs. Statique et pure (aucun accès disque) : partagée avec
     * WikiSyncService, qui l'applique au contenu récupéré à distance plutôt
     * qu'au fichier local, pour connaître la liste des pages à synchroniser.
     *
     * @return array<string, string[]> Nom de groupe ("" pour le sommet) => slugs
     */
    public static function parseSidebarMarkdown(string $raw): array
    {
        $groups  = [];
        $current = self::TOP_GROUP;

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '' || str_contains($line, '🌐')) {
                continue;
            }

            // En-tête de groupe : **Texte** (et non un lien **[Texte](Cible)**)
            if (preg_match('/^\*\*([^\[*][^*]*)\*\*$/', $line, $m)) {
                $current = trim($m[1]);
                $groups[$current] ??= [];
                continue;
            }

            if (preg_match('/\[([^\]]+)\]\(([^)]+)\)/', $line, $m)) {
                // Lien relatif (Installation) ou absolu du wiki (/AudricSan/Kintai/wiki/Installation, noms encodés).
                $slug = rawurldecode(basename(trim($m[2])));
                if (self::isValidSlug($slug)) {
                    $groups[$current] ??= [];
                    $groups[$current][] = $slug;
                }
            }
        }

        return $groups;
    }

    // -------------------------------------------------------------------------
    // Rendu du contenu d'une page
    // -------------------------------------------------------------------------

    /**
     * Supprime la ligne de sélection de langue générée par le wiki
     * (ex: "🌐 **English** · [Français](fr/Home) · [日本語](ja/Home)") :
     * l'application affiche son propre sélecteur de langue.
     */
    private function stripLanguageSwitchLine(string $md): string
    {
        $lines = array_filter(
            explode("\n", $md),
            static fn (string $line): bool => !str_contains($line, '🌐')
        );

        return implode("\n", $lines);
    }

    /**
     * Réécrit les liens Markdown internes du wiki (chemins relatifs façon
     * GitHub Wiki, ex: "Installation", "../Home", "../ja/Home") en liens vers
     * les routes /docs/{lang}/{page} de l'application. Les liens externes
     * (http/https/mailto) et les ancres ("#...") sont laissés tels quels ; les
     * liens internes qui ne résolvent vers aucune page connue sont dégradés en
     * texte simple.
     */
    private function linkifyInternalPages(string $md, string $lang): string
    {
        return preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function (array $m) use ($lang): string {
                [$full, $label, $target] = $m;

                if (preg_match('/^https?:|^mailto:|^#/', $target)) {
                    return $full;
                }

                [$resolvedLang, $resolvedSlug] = $this->resolveWikiLink($lang, $target);

                if ($resolvedLang !== null && $this->pageExists($resolvedLang, $resolvedSlug)) {
                    $url = route_url('docs.show', ['lang' => $resolvedLang, 'page' => $resolvedSlug]);
                    return '[' . $label . '](' . $url . ')';
                }

                return $label;
            },
            $md
        );
    }

    /**
     * Résout un lien interne du wiki vers une paire [langue, clé de page]. Le wiki est à plat : la cible est un nom de
     * page (anglais ou traduit), éventuellement précédé du chemin absolu du wiki ou d'un ancien « ../ » / « fr/ ».
     * Retourne [null, null] si non résoluble.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveWikiLink(string $currentLang, string $target): array
    {
        $name = rawurldecode(basename((string) strtok($target, '#')));
        if (!self::isValidSlug($name)) {
            return [null, null];
        }

        $maps = $this->slugMaps();
        foreach (['fr', 'ja'] as $lang) {
            if (isset($maps['key'][$lang][$name])) {
                return [$lang, $maps['key'][$lang][$name]];
            }
        }

        // Page anglaise : rien à convertir. Un lien d'une page traduite vers un nom introuvable reste « anglais ».
        return ['en', $name];
    }
    /**
     * Titre affiché d'une page : premier titre H1 de son contenu (dans la
     * langue donnée, ou à défaut la version anglaise), sinon le slug humanisé.
     */
    private function pageTitle(string $lang, string $slug): string
    {
        $path = $this->resolvePath($lang, $slug);

        if (!file_exists($path)) {
            $path = $this->resolvePath('en', $slug);
        }

        if (file_exists($path)) {
            $raw = (string) file_get_contents($path);
            if (preg_match('/^#\s+(.+)$/m', $raw, $m)) {
                return trim($m[1]);
            }
        }

        return str_replace(['-', '_'], ' ', $slug);
    }
}
