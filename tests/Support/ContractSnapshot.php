<?php

declare(strict_types=1);

namespace Tests\Support;

use kintai\Contract\Contract;

/**
 * Instantané machine-lisible du contrat de bundle (surface de classes, fonctions, clés de traduction, signatures de
 * Bundle) : `contract.snapshot.json` à la racine du dépôt, régénéré par `php scripts/export-contract.php --write`.
 *
 * Garde-fou de version (SemVer) : un retrait ou un changement d'une entrée STABLE exige une version MAJEURE supérieure ;
 * tout autre changement du contrat (ajout compris) exige au moins une version supérieure. Le test de CI échoue tant que
 * l'instantané versionné ne correspond pas au contrat réel, et le script refuse d'écrire un instantané qui
 * contreviendrait à la règle.
 */
final class ContractSnapshot
{
    /** @return array<string, mixed> */
    public static function export(): array
    {
        $root = dirname(__DIR__, 2);

        $surface = [];
        foreach ((require $root . '/app/Contract/surface.php') as $alias => $entry) {
            $surface[$alias] = ['status' => $entry['status'], 'target' => $entry['target']];
        }
        ksort($surface);

        $functions = [];
        $declared  = (require $root . '/app/Contract/functions.php')['declared'];
        foreach ($declared as $name => $entry) {
            $functions[$name] = ['status' => $entry['status'], 'params' => $entry['params'], 'returns' => $entry['returns']];
        }
        ksort($functions);

        $keys = require $root . '/app/Contract/translation-keys.php';
        $translations = [];
        foreach ($keys['stable'] as $k) {
            $translations[$k] = 'stable';
        }
        foreach ($keys['provisoire'] as $k) {
            $translations[$k] = 'provisoire';
        }
        ksort($translations);

        $bundle = [];
        $class  = new \ReflectionClass(\kintai\Contract\Bundle::class);
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || str_starts_with($method->getName(), '__')) {
                continue;
            }
            $params = array_map(
                static fn(\ReflectionParameter $p): string => ($p->getType() ? $p->getType() . ' ' : '') . '$' . $p->getName() . ($p->isOptional() ? '?' : ''),
                $method->getParameters()
            );
            $bundle[$method->getName()] = '(' . implode(', ', $params) . '): ' . ($method->getReturnType() ?? 'mixed');
        }
        ksort($bundle);

        return [
            'version'      => Contract::VERSION,
            'surface'      => $surface,
            'functions'    => $functions,
            'translations' => $translations,
            'bundle'       => $bundle,
        ];
    }

    /**
     * Raisons pour lesquelles passer de $old à $new viole la règle de version ; vide si le changement est permis.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    public static function versionViolations(array $old, array $new): array
    {
        $changed = $old !== $new;
        if (!$changed) {
            return [];
        }

        $errors = [];
        $oldVersion = (string) $old['version'];
        $newVersion = (string) $new['version'];
        $oldWithoutVersion = array_diff_key($old, ['version' => 1]);
        $newWithoutVersion = array_diff_key($new, ['version' => 1]);

        if ($oldWithoutVersion !== $newWithoutVersion && version_compare($newVersion, $oldVersion, '<=')) {
            $errors[] = "Le contrat a changé : la version doit dépasser $oldVersion (elle est $newVersion).";
        }

        $breaking = self::breakingChanges($old, $new);
        if ($breaking !== [] && (int) explode('.', $newVersion)[0] <= (int) explode('.', $oldVersion)[0]) {
            $errors[] = 'Changement incompatible sans version MAJEURE : ' . implode(' ; ', $breaking);
        }

        return $errors;
    }

    /**
     * Entrées stables retirées ou dont la signature change (alias vers une autre classe, paramètres, type de retour,
     * statut abaissé), ainsi que les méthodes de Bundle retirées ou modifiées.
     *
     * @return list<string>
     */
    public static function breakingChanges(array $old, array $new): array
    {
        $out = [];
        foreach (['surface', 'functions', 'translations'] as $section) {
            foreach ($old[$section] as $name => $entry) {
                $status = is_array($entry) ? $entry['status'] : $entry;
                if ($status !== 'stable') {
                    continue;
                }
                if (!array_key_exists($name, $new[$section])) {
                    $out[] = "$section : « $name » (stable) retiré";
                } elseif ($new[$section][$name] != $entry) {
                    $out[] = "$section : « $name » (stable) modifié";
                }
            }
        }
        foreach ($old['bundle'] as $method => $signature) {
            if (!array_key_exists($method, $new['bundle'])) {
                $out[] = "Bundle::$method retirée";
            } elseif ($new['bundle'][$method] !== $signature) {
                $out[] = "Bundle::$method modifiée";
            }
        }
        return $out;
    }
}
