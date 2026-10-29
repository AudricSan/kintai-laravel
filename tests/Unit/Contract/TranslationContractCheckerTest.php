<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Contract;

use kintai\Tests\Support\TranslationContractChecker;
use PHPUnit\Framework\TestCase;

/** Contrôle des traductions d'un bundle contre le contrat : chaque règle sur un bundle d'essai. */
final class TranslationContractCheckerTest extends TestCase
{
    private string $dir;
    private string $coreLang;

    protected function setUp(): void
    {
        $this->dir      = sys_get_temp_dir() . '/kintai_tcc_' . bin2hex(random_bytes(4));
        $this->coreLang = $this->dir . '/core-lang';
        mkdir($this->coreLang, 0755, true);
        mkdir($this->dir . '/bundle/lang', 0755, true);
        mkdir($this->dir . '/bundle/Views', 0755, true);
        foreach (['fr', 'en', 'ja'] as $l) {
            file_put_contents($this->coreLang . "/$l.json", json_encode(['cancel' => 'Annuler', 'greeting' => 'Bonjour :name', 'save' => 'Enregistrer']));
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function write(string $locale, array $own): void
    {
        file_put_contents($this->dir . "/bundle/lang/$locale.json", json_encode($own));
    }

    private function check(string $slug = 'daily-report'): array
    {
        return TranslationContractChecker::violations($this->dir . '/bundle', $slug, $this->coreLang);
    }

    public function testACleanBundleHasNoViolation(): void
    {
        foreach (['fr', 'en', 'ja'] as $l) {
            $this->write($l, ['daily_report_title' => 'X :n', 'bundle_daily_report' => 'B', 'bundle_daily_report_desc' => 'D', 'notif_dr_sent' => 'N', 'audit_dr_x' => 'A', 'cron_job_dr' => 'C', 'perm_category_daily_reports' => 'P']);
        }
        file_put_contents($this->dir . '/bundle/Views/v.php', "<?= __('daily_report_title', ['n' => 1]) ?> <?= __('cancel') ?> <?= __('save') ?>");

        $this->assertSame(['parity' => [], 'placeholders' => [], 'prefix' => [], 'collisions' => [], 'unknown' => []], $this->check());
    }

    public function testEveryRuleIsReported(): void
    {
        $this->write('fr', ['daily_report_a' => 'A :x', 'other_key' => 'O', 'greeting' => 'Salut']);
        $this->write('en', ['daily_report_a' => 'A :y', 'other_key' => 'O', 'greeting' => 'Hi']);
        $this->write('ja', ['daily_report_a' => 'A :x', 'greeting' => 'こんにちは']);
        file_put_contents($this->dir . '/bundle/Views/v.php', "<?= __('ghost_key') ?> <?= __('daily_report_' . \$dyn) ?>");

        $r = $this->check();

        $this->assertSame(['other_key : absente en ja'], $r['parity']);
        $this->assertSame(['daily_report_a : paramètres différents selon la langue (en)'], $r['placeholders']);
        // greeting est aussi hors préfixe : redéfinir une clé du Core sans préfixe cumule les deux défauts.
        $this->assertSame(['other_key : préfixe « daily_report_ » attendu', 'greeting : préfixe « daily_report_ » attendu'], $r['prefix']);
        $this->assertCount(1, $r['collisions']);
        $this->assertStringContainsString('greeting', $r['collisions'][0]);
        $this->assertCount(1, $r['unknown']);
        $this->assertStringContainsString('ghost_key', $r['unknown'][0]);
    }

    public function testTheContractListHasNoOverlapAndOnlyKnownCoreKeys(): void
    {
        $keys = require dirname(__DIR__, 3) . '/app/Contract/translation-keys.php';
        $core = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/lang/fr.json'), true);

        $this->assertSame([], array_values(array_intersect($keys['stable'], $keys['provisoire'])));
        foreach (array_merge($keys['stable'], $keys['provisoire']) as $key) {
            $this->assertArrayHasKey($key, $core, "$key est déclarée au contrat mais absente du Core");
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }
        @rmdir($dir);
    }
}
