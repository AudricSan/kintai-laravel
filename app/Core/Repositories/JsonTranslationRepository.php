<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/**
 * Fusionne les traductions du Core (lang/*.json) avec celles de chaque bundle
 * détecté sur le disque : les bundles legacy du monorepo
 * (app/Bundles/<Name>/lang/*.json) et les bundles installés dynamiquement
 * (storage/bundles/<slug>/<version>/lang/*.json, chemins déjà résolus par
 * l'appelant — contrairement au dossier legacy, leur profondeur varie selon
 * la version active, donc pas de glob générique possible ici). Un bundle ne
 * définit que ses propres clés ; toute clé absente de son fichier retombe
 * naturellement sur celle du Core (elles cohabitent dans le même espace de
 * noms plat, comme avant l'introduction des bundles).
 *
 * Surcharge d'une clé du Core (contrat 1.25) : un bundle la déclare EXPLICITEMENT dans
 * lang/overrides/{locale}.json ; ces fichiers sont appliqués en dernier (ordre des slugs). Une surcharge doit garder
 * les mêmes paramètres « :nom » que la valeur qu'elle remplace, sinon elle est ignorée. Redéfinir une clé du Core
 * dans lang/{locale}.json sans la déclarer est une collision non déclarée : tolérée tant que strictCollisions() est
 * faux (transition), ignorée ensuite (la valeur du Core l'emporte) ; undeclaredCollisions() les liste.
 */
final class JsonTranslationRepository implements TranslationRepositoryInterface
{
    private string $langPath;
    private ?string $legacyBundlesDir;
    /** @var string[] */
    private array $installedBundleRoots;

    /** @var array<string, array<string,string>> Cache des fichiers déjà lus (chemin => données). */
    private array $fileCache = [];

    /** Vrai : une collision non déclarée avec le Core est ignorée (le Core l'emporte) ; faux : tolérée (transition). */
    private bool $strictCollisions = false;

    /** @param string[] $installedBundleRoots Chemins absolus de chaque bundle installé actif (storage/bundles/<slug>/<version>). */
    public function __construct(string $langPath, ?string $legacyBundlesDir = null, array $installedBundleRoots = [])
    {
        $this->langPath = rtrim($langPath, '/\\');
        $this->legacyBundlesDir = $legacyBundlesDir !== null ? rtrim($legacyBundlesDir, '/\\') : null;
        $this->installedBundleRoots = array_map(fn(string $d) => rtrim($d, '/\\'), $installedBundleRoots);
    }

    public function strictCollisions(bool $strict): void
    {
        $this->strictCollisions = $strict;
        $this->fileCache = [];
    }

    /**
     * Clés qu'un bundle redéfinit dans son lang/{locale}.json alors que le Core les fournit déjà, sans les avoir
     * déclarées dans lang/overrides/{locale}.json.
     *
     * @return array<string, list<string>> clé => racines des bundles fautifs
     */
    public function undeclaredCollisions(string $locale): array
    {
        $core = $this->readJson($this->filePath($locale));
        $out  = [];
        foreach ($this->bundleLangFiles($locale) as $root => $file) {
            $declared = $this->readJson($root . '/lang/overrides/' . $locale . '.json');
            foreach ($this->readJson($file) as $key => $_) {
                if (array_key_exists($key, $core) && !array_key_exists($key, $declared)) {
                    $out[$key][] = $root;
                }
            }
        }
        return $out;
    }

    /**
     * Surcharges déclarées : clé => racines des bundles qui la surchargent (celles réellement appliquées).
     *
     * @return array<string, list<string>>
     */
    public function declaredOverrides(string $locale): array
    {
        $out = [];
        foreach ($this->bundleRoots() as $root) {
            foreach ($this->readJson($root . '/lang/overrides/' . $locale . '.json') as $key => $_) {
                $out[$key][] = $root;
            }
        }
        return $out;
    }

    public function findByLocale(string $locale): array
    {
        return $this->load($locale);
    }

    public function findValue(string $locale, string $key): ?string
    {
        return $this->load($locale)[$key] ?? null;
    }

    public function findAllKeys(): array
    {
        $keys = [];
        foreach ($this->localeCodes() as $locale) {
            foreach ($this->layerFiles($locale) as $file) {
                $keys = array_merge($keys, array_keys($this->readJson($file)));
            }
        }
        return array_values(array_unique($keys));
    }

    public function save(string $locale, string $key, string $value): void
    {
        $file = $this->fileOwning($locale, $key) ?? $this->filePath($locale);
        $data = $this->readJson($file);
        $data[$key] = $value;
        $this->write($file, $data);
    }

    public function delete(string $locale, string $key): int
    {
        $file = $this->fileOwning($locale, $key);
        if ($file === null) {
            return 0;
        }
        $data = $this->readJson($file);
        unset($data[$key]);
        $this->write($file, $data);
        return 1;
    }

    public function countByLocale(string $locale): int
    {
        return count($this->load($locale));
    }

    /** @return string[] Codes de langue connus (fr, en, ja...), dérivés des fichiers lang/*.json du Core. */
    private function localeCodes(): array
    {
        return array_map(
            fn(string $f) => basename($f, '.json'),
            $this->localeFiles(),
        );
    }

    /** @return string[] */
    private function localeFiles(): array
    {
        $files = glob($this->langPath . '/*.json') ?: [];
        return array_values(array_filter($files, fn($f) => basename($f) !== 'languages.json'));
    }

    /**
     * Fichiers à fusionner pour une locale donnée, dans l'ordre de priorité
     * croissante : celui du Core d'abord (base), puis celui de chaque bundle
     * trouvé sur le disque (dernier lu = valeur retenue en cas de collision).
     *
     * @return string[]
     */
    private function layerFiles(string $locale): array
    {
        $files = [$this->filePath($locale)];

        if ($this->legacyBundlesDir !== null) {
            $bundleFiles = glob($this->legacyBundlesDir . '/*/lang/' . $locale . '.json') ?: [];
            sort($bundleFiles);
            $files = array_merge($files, $bundleFiles);
        }

        $installedFiles = [];
        foreach ($this->installedBundleRoots as $root) {
            $file = $root . '/lang/' . $locale . '.json';
            if (is_file($file)) {
                $installedFiles[] = $file;
            }
        }
        sort($installedFiles);

        return array_merge($files, $installedFiles);
    }

    private function filePath(string $locale): string
    {
        return $this->langPath . '/' . $locale . '.json';
    }

    private function load(string $locale): array
    {
        $core   = $this->readJson($this->filePath($locale));
        $merged = [];
        foreach ($this->layerFiles($locale) as $file) {
            $merged = array_merge($merged, $this->readJson($file));
        }

        // Collision non déclarée : en mode strict, la valeur du Core l'emporte.
        if ($this->strictCollisions) {
            foreach ($this->undeclaredCollisions($locale) as $key => $_) {
                $merged[$key] = $core[$key];
            }
        }

        // Surcharges déclarées, appliquées en dernier ; une surcharge qui change les paramètres :nom est refusée.
        foreach ($this->bundleRoots() as $root) {
            foreach ($this->readJson($root . '/lang/overrides/' . $locale . '.json') as $key => $value) {
                $original = $merged[$key] ?? null;
                if ($original === null || self::placeholders((string) $original) === self::placeholders((string) $value)) {
                    $merged[$key] = $value;
                }
            }
        }
        return $merged;
    }

    /** Paramètres « :nom » d'une valeur, triés, pour comparer une surcharge à l'original. */
    public static function placeholders(string $value): array
    {
        preg_match_all('/:[A-Za-z_]\w*/', $value, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);
        return $found;
    }

    /** @return list<string> racines des bundles (legacy puis installés), triées par chemin pour un ordre déterministe */
    private function bundleRoots(): array
    {
        $roots = [];
        if ($this->legacyBundlesDir !== null) {
            $roots = glob($this->legacyBundlesDir . '/*', GLOB_ONLYDIR) ?: [];
            sort($roots);
        }
        $installed = $this->installedBundleRoots;
        sort($installed);
        return array_merge($roots, $installed);
    }

    /** @return array<string, string> racine du bundle => son lang/{locale}.json (seulement ceux qui existent) */
    private function bundleLangFiles(string $locale): array
    {
        $out = [];
        foreach ($this->bundleRoots() as $root) {
            $file = $root . '/lang/' . $locale . '.json';
            if (is_file($file)) {
                $out[$root] = $file;
            }
        }
        return $out;
    }

    /** Fichier possédant déjà $key pour $locale (priorité aux bundles), ou null si la clé n'existe nulle part. */
    private function fileOwning(string $locale, string $key): ?string
    {
        $owner = null;
        foreach ($this->layerFiles($locale) as $file) {
            if (array_key_exists($key, $this->readJson($file))) {
                $owner = $file;
            }
        }
        return $owner;
    }

    private function readJson(string $file): array
    {
        if (array_key_exists($file, $this->fileCache)) {
            return $this->fileCache[$file];
        }
        if (!file_exists($file)) {
            return $this->fileCache[$file] = [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return $this->fileCache[$file] = (is_array($data) ? $data : []);
    }

    private function write(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        ksort($data);
        file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        $this->fileCache[$file] = $data;
    }
}
