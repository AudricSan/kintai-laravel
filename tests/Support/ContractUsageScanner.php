<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Vérifie qu'un bundle n'utilise du Core que le contrat de bundle (kintai\Contract\…), plus une liste explicite de
 * classes « en attente d'extraction » : celles qui appartiennent au bundle mais vivent encore dans le Core
 * (dépôts, modèles et services propres au bundle, déplacés dans son dépôt à l'étape 4 du plan du contrat).
 *
 * Utilisé par la CI de chaque bundle (ContractUsageTest) : le socle de tests des bundles charge tests/Support du Core.
 */
final class ContractUsageScanner
{
    /**
     * @param list<string> $paths            Fichiers ou dossiers à analyser (src/, Views/, routes.php…)
     * @param list<string> $pendingExtraction Classes du Core encore tolérées (noms complets, sans « \ » initial)
     * @return list<string> Violations « fichier:ligne : classe »
     */
    public static function violations(array $paths, array $pendingExtraction = []): array
    {
        $violations = [];
        foreach (self::phpFiles($paths) as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match_all('/\\\\?\b(App\\\\[A-Za-z0-9_\\\\]+)/', $line, $m) === 0) {
                    continue;
                }
                foreach ($m[1] as $class) {
                    $class = rtrim($class, '\\');
                    if (!in_array($class, $pendingExtraction, true)) {
                        $violations[] = str_replace('\\', '/', $file) . ':' . ($i + 1) . ' : ' . $class;
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * Appels de fonctions globales hors contrat : une fonction que ni PHP, ni app/Contract/functions.php (« declared »),
     * ni le bundle lui-même ne définit. Détecte donc aussi bien un helper interne du Core (hash_token, asset_version…)
     * qu'une fonction inexistante. Analyse par tokenizer : les méthodes (->f(), ::f()), les constructeurs et les
     * déclarations ne sont pas des appels de fonctions globales.
     *
     * @param list<string> $paths Fichiers ou dossiers à analyser (src/, Views/, routes.php…)
     * @return list<string> Violations « fichier:ligne : fonction »
     */
    public static function functionViolations(array $paths): array
    {
        $contract = require dirname(__DIR__, 2) . '/app/Contract/functions.php';
        $allowed  = array_fill_keys(array_keys($contract['declared']), true);
        $native   = array_fill_keys(get_defined_functions()['internal'], true);

        $files   = self::phpFiles($paths);
        $tokens  = [];
        $own     = [];
        foreach ($files as $file) {
            $tokens[$file] = token_get_all((string) file_get_contents($file));
            // Fonctions que le bundle déclare lui-même (hors méthodes, qui suivent une classe/trait).
            foreach (self::calls($tokens[$file], true) as [$name]) {
                $own[strtolower($name)] = true;
            }
        }

        $violations = [];
        foreach ($files as $file) {
            foreach (self::calls($tokens[$file], false) as [$name, $line]) {
                $lower = strtolower($name);
                if (isset($allowed[$name]) || isset($native[$lower]) || isset($own[$lower]) || in_array($lower, ['isset', 'empty', 'list', 'array', 'exit', 'die', 'unset', 'echo', 'print', 'eval', 'include', 'require'], true)) {
                    continue;
                }
                $violations[] = str_replace('\\', '/', $file) . ':' . $line . ' : ' . $name;
            }
        }
        return $violations;
    }

    /**
     * @param array $tokens
     * @param bool  $declarations true : noms des fonctions déclarées ; false : noms des fonctions appelées
     * @return list<array{0: string, 1: int}> nom, ligne
     */
    private static function calls(array $tokens, bool $declarations): array
    {
        $out   = [];
        $count = count($tokens);
        $prev  = static function (int $i) use ($tokens): mixed {
            for ($k = $i - 1; $k >= 0; $k--) {
                if (!is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    return $tokens[$k];
                }
            }
            return null;
        };
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $before = $prev($i);
            $isDecl = is_array($before) && $before[0] === T_FUNCTION;
            if ($declarations) {
                if ($isDecl) {
                    $out[] = [$t[1], $t[2]];
                }
                continue;
            }
            if ($isDecl) {
                continue;
            }
            if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_NAMESPACE, T_USE, T_CONST, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)) {
                continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
                $j++;
            }
            if (($tokens[$j] ?? null) === '(') {
                $out[] = [ltrim($t[1], '\\'), $t[2]];
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }
}
