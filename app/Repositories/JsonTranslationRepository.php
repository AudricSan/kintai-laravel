<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Translation\Translator;

/**
 * Traductions JSON de Laravel (lang/{locale}.json, plus le lang/ de chaque bundle enregistré par
 * loadJsonTranslationsFrom) vues par l'éditeur de traductions de /admin. La lecture passe par le chargeur de Laravel ;
 * l'écriture va toujours dans lang/{locale}.json de l'application, qui l'emporte sur les fichiers des bundles : modifier
 * une clé d'un bundle depuis l'éditeur la surcharge sans toucher au bundle.
 */
final class JsonTranslationRepository implements TranslationRepositoryInterface
{
    private string $langPath;

    public function __construct(string $langPath, private readonly Translator $translator)
    {
        $this->langPath = rtrim($langPath, '/\\');
    }

    public function findByLocale(string $locale): array
    {
        return $this->translator->getLoader()->load($locale, '*', '*');
    }

    public function findValue(string $locale, string $key): ?string
    {
        return $this->findByLocale($locale)[$key] ?? null;
    }

    public function findAllKeys(): array
    {
        $keys = [];
        foreach ($this->localeCodes() as $locale) {
            $keys = array_merge($keys, array_keys($this->findByLocale($locale)));
        }

        return array_values(array_unique($keys));
    }

    public function save(string $locale, string $key, string $value): void
    {
        $data = $this->readFile($locale);
        $data[$key] = $value;
        $this->write($locale, $data);
    }

    public function delete(string $locale, string $key): int
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
        return count($this->findByLocale($locale));
    }

    /** Paramètres « :nom » d'une valeur, triés, pour comparer les langues d'une même clé. */
    public static function placeholders(string $value): array
    {
        preg_match_all('/:[A-Za-z_]\w*/', $value, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** @return string[] Codes de langue connus (fr, en, ja…), dérivés des fichiers lang/*.json. */
    private function localeCodes(): array
    {
        $files = glob($this->langPath . '/*.json') ?: [];

        return array_values(array_filter(
            array_map(static fn(string $f): string => basename($f, '.json'), $files),
            static fn(string $code): bool => $code !== 'languages',
        ));
    }

    private function readFile(string $locale): array
    {
        $file = $this->langPath . '/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? $data : [];
    }

    private function write(string $locale, array $data): void
    {
        if (!is_dir($this->langPath)) {
            mkdir($this->langPath, 0777, true);
        }
        ksort($data);
        file_put_contents(
            $this->langPath . '/' . $locale . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        // Le traducteur relit les fichiers à la prochaine traduction.
        $this->translator->setLoaded([]);
    }
}
