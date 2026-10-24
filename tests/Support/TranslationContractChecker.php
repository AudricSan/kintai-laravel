<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Core\Repositories\JsonTranslationRepository;

/**
 * Contrôle les traductions d'un bundle contre le contrat (app/Contract/translation-keys.php) : utilisé par la CI de
 * chaque bundle (TranslationContractTest), comme ContractUsageScanner pour les classes et les fonctions.
 *
 * Le rapport est groupé par règle pour que chaque bundle choisisse ce qu'il impose (tout, pour la V1) :
 *  - parity       : une clé absente d'une des langues (fr/en/ja) du bundle ;
 *  - placeholders : une clé dont les paramètres « :nom » diffèrent d'une langue à l'autre ;
 *  - prefix       : une clé sans le préfixe du bundle (hors conventions réservées bundle_/notif_/audit_/cron_job_/perm_category_) ;
 *  - collisions   : une clé du Core redéfinie dans lang/{locale}.json sans être déclarée dans lang/overrides/{locale}.json ;
 *  - overrides    : une surcharge d'une clé que le Core ne fournit pas, ou qui change ses paramètres « :nom » ;
 *  - unknown      : une clé utilisée par le code (littéral __('clé')) que ni le bundle ni le contrat ne fournissent.
 */
final class TranslationContractChecker
{
    public const LOCALES = ['fr', 'en', 'ja'];

    /**
     * @param string $bundleDir racine du bundle (lang/, src/, Views/, routes.php)
     * @param string $slug      slug du bundle (« daily-report » : préfixe « daily_report_ »)
     * @param string $coreLangDir dossier lang/ du Core
     * @return array<'parity'|'placeholders'|'prefix'|'collisions'|'overrides'|'unknown', list<string>>
     */
    public static function violations(string $bundleDir, string $slug, string $coreLangDir): array
    {
        $report = ['parity' => [], 'placeholders' => [], 'prefix' => [], 'collisions' => [], 'overrides' => [], 'unknown' => []];

        $own = $overrides = $core = [];
        foreach (self::LOCALES as $locale) {
            $own[$locale]       = self::read($bundleDir . '/lang/' . $locale . '.json');
            $overrides[$locale] = self::read($bundleDir . '/lang/overrides/' . $locale . '.json');
            $core[$locale]      = self::read(rtrim($coreLangDir, '/\\') . '/' . $locale . '.json');
        }

        $prefix = str_replace('-', '_', $slug) . '_';
        $allOwn = [];
        foreach ($own as $keys) {
            $allOwn += $keys;
        }

        foreach ($allOwn as $key => $_) {
            foreach (self::LOCALES as $locale) {
                if (!array_key_exists($key, $own[$locale])) {
                    $report['parity'][] = "$key : absente en $locale";
                }
            }
            $reference = null;
            foreach (self::LOCALES as $locale) {
                if (!isset($own[$locale][$key])) {
                    continue;
                }
                $found = JsonTranslationRepository::placeholders((string) $own[$locale][$key]);
                if ($reference !== null && $reference !== $found) {
                    $report['placeholders'][] = "$key : paramètres différents selon la langue ($locale)";
                    break;
                }
                $reference ??= $found;
            }
            if (!self::hasAllowedName($key, $prefix, $slug)) {
                $report['prefix'][] = "$key : préfixe « $prefix » attendu";
            }
            foreach (self::LOCALES as $locale) {
                if (array_key_exists($key, $core[$locale]) && !array_key_exists($key, $overrides[$locale])) {
                    $report['collisions'][] = "$key : redéfinit une clé du Core sans la déclarer dans lang/overrides/$locale.json";
                    break;
                }
            }
        }

        foreach (self::LOCALES as $locale) {
            foreach ($overrides[$locale] as $key => $value) {
                if (!array_key_exists($key, $core[$locale])) {
                    $report['overrides'][] = "$key ($locale) : surcharge d'une clé que le Core ne fournit pas";
                } elseif (JsonTranslationRepository::placeholders((string) $core[$locale][$key]) !== JsonTranslationRepository::placeholders((string) $value)) {
                    $report['overrides'][] = "$key ($locale) : la surcharge change les paramètres :nom de l'original";
                }
            }
        }

        $contract = require dirname(__DIR__, 2) . '/app/Contract/translation-keys.php';
        $common   = array_fill_keys(array_merge($contract['stable'], $contract['provisoire']), true);
        foreach (self::usedKeys($bundleDir) as $key => $where) {
            $provided = isset($allOwn[$key]) || isset($common[$key]);
            // Une clé surchargée par le bundle est fournie par lui.
            foreach (self::LOCALES as $locale) {
                $provided = $provided || isset($overrides[$locale][$key]);
            }
            if (!$provided) {
                $report['unknown'][] = "$key : utilisée ($where) mais fournie ni par le bundle ni par le contrat";
            }
        }

        return $report;
    }

    /** Préfixe du bundle ou convention réservée (libellés du bundle, des notifications, du journal, des tâches). */
    private static function hasAllowedName(string $key, string $prefix, string $slug): bool
    {
        $underscored = str_replace('-', '_', $slug);
        return str_starts_with($key, $prefix)
            || $key === rtrim($prefix, '_')
            || $key === 'bundle_' . $underscored
            || $key === 'bundle_' . $underscored . '_desc'
            || str_starts_with($key, 'notif_')
            || str_starts_with($key, 'audit_')
            || str_starts_with($key, 'cron_job_')
            // La page des rôles du Core construit « perm_category_{catégorie} » : la catégorie est une clé de
            // permission stockée en base, qui ne suit pas forcément le slug (open_shifts, swaps…).
            || str_starts_with($key, 'perm_category_');
    }

    /** @return array<string, string> clé => « fichier:ligne » de sa première utilisation littérale */
    private static function usedKeys(string $bundleDir): array
    {
        $used = [];
        foreach (['src', 'Views', 'routes.php'] as $path) {
            $full = $bundleDir . '/' . $path;
            $files = [];
            if (is_file($full)) {
                $files[] = $full;
            } elseif (is_dir($full)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                        $files[] = $f->getPathname();
                    }
                }
            }
            sort($files);
            foreach ($files as $file) {
                foreach (file($file) ?: [] as $i => $line) {
                    if (preg_match_all('/\b__\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*[,)]/', $line, $m)) {
                        foreach ($m[1] as $key) {
                            $used[$key] ??= str_replace('\\', '/', $file) . ':' . ($i + 1);
                        }
                    }
                }
            }
        }
        return $used;
    }

    /** @return array<string, string> */
    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
}
