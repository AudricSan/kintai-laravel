<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Translation\Translator;

/**
 * Éditeur de traductions de /admin. La lecture passe par le chargeur de Laravel (lang/{locale}.json, plus le lang/ de
 * chaque bundle enregistré par loadJsonTranslationsFrom) ; l'écriture va toujours dans lang/{locale}.json de
 * l'application, qui l'emporte sur les fichiers des bundles : modifier une clé d'un bundle depuis l'éditeur la surcharge
 * sans toucher au bundle.
 */
final class TranslationManagementService
{
    public function __construct(
        private readonly Translator $translator,
        /** Dossier des fichiers {locale}.json ; lang/ de l'application par défaut. */
        private readonly ?string $langPath = null,
    ) {}

    /**
     * @return array{items: list<array{key:string,reference:string,value:string}>, total:int}
     */
    public function browseKeys(string $locale, string $search = '', int $page = 1, int $perPage = 50): array
    {
        $allKeys = $this->allKeys();
        sort($allKeys);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $allKeys = array_values(array_filter(
                $allKeys,
                fn($k) => str_contains(mb_strtolower($k), $needle)
            ));
        }

        $total    = count($allKeys);
        $page     = max(1, $page);
        $pageKeys = array_slice($allKeys, ($page - 1) * $perPage, $perPage);

        $localeValues    = $this->forLocale($locale);
        $referenceValues = $this->forLocale('en');

        $items = [];
        foreach ($pageKeys as $key) {
            $items[] = [
                'key'       => $key,
                'reference' => $referenceValues[$key] ?? '',
                'value'     => $localeValues[$key] ?? '',
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,string> clé → texte pour cette langue */
    public function forLocale(string $locale): array
    {
        return $this->translator->getLoader()->load($locale, '*', '*');
    }

    /** @return string[] union des clés présentes dans toutes les langues */
    public function allKeys(): array
    {
        $keys = [];
        foreach ($this->localeCodes() as $locale) {
            $keys = array_merge($keys, array_keys($this->forLocale($locale)));
        }

        return array_values(array_unique($keys));
    }

    public function saveValue(string $locale, string $key, string $value): void
    {
        $data = $this->readFile($locale);
        $data[$key] = $value;
        $this->write($locale, $data);
    }

    /** Supprime une clé pour une langue. Retourne 1 si supprimée, 0 sinon. */
    public function deleteValue(string $locale, string $key): int
    {
        $data = $this->readFile($locale);
        if (!array_key_exists($key, $data)) {
            return 0;
        }
        unset($data[$key]);
        $this->write($locale, $data);

        return 1;
    }

    public function countByLocale(string $locale): int
    {
        return count($this->forLocale($locale));
    }

    /** Paramètres « :nom » d'une valeur, triés, pour comparer les langues d'une même clé. */
    public static function placeholders(string $value): array
    {
        preg_match_all('/:[A-Za-z_]\w*/', $value, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** @return string[] codes de langue connus (fr, en, ja…), dérivés des fichiers lang/*.json */
    private function localeCodes(): array
    {
        return array_map(static fn(string $f): string => basename($f, '.json'), glob($this->path('*.json')) ?: []);
    }

    private function path(string $file = ''): string
    {
        return $this->langPath === null ? lang_path($file) : rtrim($this->langPath, '/') . ($file === '' ? '' : '/' . $file);
    }

    private function readFile(string $locale): array
    {
        $file = $this->path($locale . '.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? $data : [];
    }

    private function write(string $locale, array $data): void
    {
        if (!is_dir($this->path())) {
            mkdir($this->path(), 0777, true);
        }
        ksort($data);
        file_put_contents(
            $this->path($locale . '.json'),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        // Le traducteur relit les fichiers à la prochaine traduction.
        $this->translator->setLoaded([]);
    }
}
