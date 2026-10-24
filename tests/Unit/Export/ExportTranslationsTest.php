<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;

/** Chaque texte de la page « Exports » et chaque en-tête de colonne du Core est traduit en fr, en et ja. */
final class ExportTranslationsTest extends TestCase
{
    /** @return list<string> */
    private function keysUsed(): array
    {
        $root   = dirname(__DIR__, 3);
        $keys   = [];
        $source = (string) file_get_contents($root . '/app/Core/Extension/ExportDatasets.php');

        // Libellés des jeux de données (« export_ds_… ») et colonnes (listes passées à columns(), clé « export_col_<nom> »).
        preg_match_all("/'(export_ds_[a-z_]+)'/", $source, $m);
        array_push($keys, ...$m[1]);
        preg_match_all('/\$this->columns\(\[([^\]]+)\]/', $source, $m);
        foreach ($m[1] as $list) {
            preg_match_all("/'([a-z_]+)'/", $list, $names);
            foreach ($names[1] as $name) {
                $keys[] = 'export_col_' . $name;
            }
        }

        // Textes de la page, de son contrôleur, du contrôle d'accès et messages d'erreur construits par suffixe.
        foreach (['/app/UI/View/exports/index.php', '/app/UI/Controller/Web/ExportController.php', '/app/Core/Export/ExportAccess.php'] as $file) {
            preg_match_all("/__\('(export_[a-z_]+)'\)/", (string) file_get_contents($root . $file), $m);
            array_push($keys, ...$m[1]);
        }
        array_push($keys, 'export_error_forbidden', 'export_error_ics_scope');

        return array_values(array_unique($keys));
    }

    public function testEveryKeyExistsInEveryLanguage(): void
    {
        $keys = $this->keysUsed();
        $this->assertGreaterThan(40, count($keys), 'les clés ont bien été relevées');

        foreach (['fr', 'en', 'ja'] as $locale) {
            $lang = json_decode((string) file_get_contents(dirname(__DIR__, 3) . "/lang/$locale.json"), true);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $lang, "Clé « $key » absente de lang/$locale.json");
                $this->assertNotSame('', trim((string) $lang[$key]), "Clé « $key » vide dans lang/$locale.json");
            }
        }
    }
}
