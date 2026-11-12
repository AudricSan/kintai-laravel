<?php

namespace App\Docs;

/**
 * Pages de la documentation synchronisées depuis le wiki GitHub, dans `storage/app/docs` (`kintai.docs.path`) : une page
 * `Nom.md` par page du wiki, plus `_Sidebar.md` (le sommaire). Le dossier est hors du dossier public ; les noms de page sont
 * validés avant tout accès disque (jamais de chemin, ni de « .. », ni de page « méta » commençant par « _ »).
 */
class DocsStore
{
    /** Taille maximale d'une page, en octets (contrôle d'intégrité de la synchronisation). */
    public const PAGE_MAX_BYTES = 524288;

    /** Taille maximale du sommaire, en octets. */
    public const SIDEBAR_MAX_BYTES = 65536;

    public const SIDEBAR = '_Sidebar';

    /**
     * Nom de page : lettres (accentuées et japonaises comprises), chiffres, tirets et soulignés ; jamais de chemin.
     */
    public static function isValidSlug(string $slug): bool
    {
        return $slug !== '' && strlen($slug) <= 150 && $slug[0] !== '_' && preg_match('/^[\p{L}\p{N}\p{M}_-]+$/u', $slug) === 1;
    }

    /**
     * Groupes de pages du sommaire (`_Sidebar.md`, format du wiki GitHub). Le groupe sans titre (liens en tête) a `label` null.
     *
     * @return list<array{label: string|null, items: list<array{slug: string, title: string}>}>
     */
    public static function parseSidebar(string $markdown): array
    {
        $groups = [];
        $current = '';

        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            $line = trim($line);

            if ($line === '' || str_contains($line, '🌐')) {
                continue;
            }

            // Titre de groupe : **Texte** (et non un lien **[Texte](Cible)**).
            if (preg_match('/^\*\*([^\[*][^*]*)\*\*$/u', $line, $m) === 1) {
                $current = trim($m[1]);
                $groups[$current] ??= [];

                continue;
            }

            if (preg_match('/\[([^\]]+)\]\(([^)]+)\)/u', $line, $m) !== 1) {
                continue;
            }

            $slug = self::slugFromTarget($m[2]);

            if ($slug !== null) {
                $groups[$current][] = ['slug' => $slug, 'title' => trim($m[1])];
            }
        }

        $result = [];
        foreach ($groups as $label => $items) {
            if ($items !== []) {
                $result[] = ['label' => (string) $label === '' ? null : (string) $label, 'items' => $items];
            }
        }

        return $result;
    }

    /**
     * Nom de page visé par la cible d'un lien du wiki (« Page », « Page.md », « /Compte/Depot/wiki/Page#ancre »), ou null si
     * la cible n'est pas une page (adresse externe, ancre seule, nom invalide).
     */
    public static function slugFromTarget(string $target): ?string
    {
        $target = trim($target);

        if ($target === '' || $target[0] === '#') {
            return null;
        }

        // Adresse avec schéma : seul un lien vers un wiki GitHub désigne une page.
        $hasScheme = preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1 || str_starts_with($target, '//');

        if ($hasScheme && ! str_contains($target, '/wiki/')) {
            return null;
        }

        $path = (string) preg_replace('/[#?].*$/', '', $target);
        $slug = (string) preg_replace('/\.md$/i', '', rawurldecode(basename($path)));

        return self::isValidSlug($slug) ? $slug : null;
    }

    public function directory(): string
    {
        return rtrim((string) config('kintai.docs.path'), '/\\');
    }

    /** La documentation a-t-elle été synchronisée au moins une fois (sommaire présent) ? */
    public function isSynced(): bool
    {
        return is_file($this->path(self::SIDEBAR));
    }

    /**
     * @return list<array{label: string|null, items: list<array{slug: string, title: string}>}>
     */
    public function tableOfContents(): array
    {
        $sidebar = $this->read(self::SIDEBAR);

        return $sidebar === null ? [] : self::parseSidebar($sidebar);
    }

    public function firstPage(): ?string
    {
        foreach ($this->tableOfContents() as $group) {
            foreach ($group['items'] as $item) {
                if ($this->has($item['slug'])) {
                    return $item['slug'];
                }
            }
        }

        return null;
    }

    public function has(string $slug): bool
    {
        return self::isValidSlug($slug) && is_file($this->path($slug));
    }

    /**
     * Contenu d'une page (ou du sommaire), null si absente ou de nom invalide.
     */
    public function read(string $slug): ?string
    {
        if (($slug !== self::SIDEBAR && ! self::isValidSlug($slug)) || ! is_file($this->path($slug))) {
            return null;
        }

        $content = @file_get_contents($this->path($slug));

        return $content === false ? null : $content;
    }

    /**
     * Écrit la page seulement si son contenu a changé.
     *
     * @return 'added'|'updated'|'unchanged'
     */
    public function write(string $slug, string $content): string
    {
        $path = $this->path($slug);
        $existing = is_file($path) ? @file_get_contents($path) : null;

        if ($existing === $content) {
            return 'unchanged';
        }

        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0755, true);
        }

        // Écriture atomique : un lecteur ne voit jamais une page à moitié écrite.
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, $content, LOCK_EX);
        rename($temporary, $path);

        return $existing === null ? 'added' : 'updated';
    }

    /**
     * Supprime les pages qui ne sont plus au sommaire du wiki.
     *
     * @param  list<string>  $keep  Noms de pages à conserver.
     * @return int Nombre de pages supprimées.
     */
    public function prune(array $keep): int
    {
        $removed = 0;
        $keep = array_flip([...$keep, self::SIDEBAR]);

        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
            if (! isset($keep[basename($file, '.md')]) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function path(string $slug): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$slug.'.md';
    }
}
