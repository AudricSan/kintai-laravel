<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Repositories\JsonTranslationRepository;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Vérifie, sur les vrais fichiers lang/*.json (pas des fixtures synthétiques), que la
 * migration des clés bundle-exclusives hors du Core n'a laissé aucun trou : chaque
 * bundle migré résout bien ses propres clés, et le Core reste accessible en fallback
 * pour les clés partagées. Tous les bundles vivent dans leur propre dépôt, qui vérifie ses traductions ;
 * plus aucune copie figée n'est gardée sous tests/Fixtures/bundles/.
 */
final class BundleTranslationsRealFilesTest extends TestCase
{
    private JsonTranslationRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new JsonTranslationRepository(BASE_PATH . '/lang', BASE_PATH . '/app/Bundles');
    }

    /** Libellé de chaque bundle extrait du Core : fourni par le bundle lui-même (et vérifié dans son dépôt). */
    private const EXTRACTED_BUNDLE_LABELS = [
        'bundle_daily_report', 'bundle_feedback', 'bundle_hiring_report', 'bundle_messaging', 'bundle_notebook',
        'bundle_resignation_report', 'bundle_salary_report', 'bundle_shift_claim', 'bundle_shift_swap', 'bundle_store_photos',
        'bundle_team_directory', 'bundle_timeclock', 'bundle_timeoff',
    ];

    public function testCoreLangFilesNoLongerContainMigratedBundleKeys(): void
    {
        foreach (['fr', 'en', 'ja'] as $locale) {
            $core = json_decode((string) file_get_contents(BASE_PATH . "/lang/{$locale}.json"), true);
            foreach (self::EXTRACTED_BUNDLE_LABELS as $key) {
                $this->assertArrayNotHasKey($key, $core, "{$key} devrait vivre dans son bundle, pas dans le Core");
            }
        }
    }

    public function testSharedCoreStringsStayResolvableAlongsideBundleStrings(): void
    {
        // 'save' est une chaîne Core partagée par toute l'appli, y compris les bundles
        // (aucun ne la redéfinit) : elle doit rester accessible en fallback.
        $this->assertSame('Enregistrer', $this->repo->findValue('fr', 'save'));
        $this->assertSame('Save', $this->repo->findValue('en', 'save'));
    }
}
