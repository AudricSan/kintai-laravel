<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\WikiIntegrityChecker;
use PHPUnit\Framework\TestCase;

/**
 * Contrôle d'un dossier de wiki avant publication. Le wiki GitHub est à plat : toutes les pages sont à la racine,
 * chaque langue sous son propre nom, déclaré par la ligne de langue de la page anglaise.
 */
final class WikiIntegrityCheckerTest extends TestCase
{
    private const BODY = "Contenu\nsur plusieurs\nlignes\nnon vides\npour ne pas\nêtre un relais\nd'ancien lien.";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai_wiki_check_' . uniqid();
        foreach (['', '/images'] as $sub) {
            mkdir($this->dir . $sub, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** Écrit une page ; $line est sa ligne de langue (null : aucune). */
    private function write(string $name, ?string $line, string $body = self::BODY): void
    {
        file_put_contents("{$this->dir}/$name.md", "# T\n\n" . ($line !== null ? $line . "\n\n" : '') . $body . "\n");
    }

    /** Une page en trois langues : $slug, "$slug-fr" et "$slug-ja". */
    private function trio(string $slug, string $body = self::BODY, string $fr = '', string $ja = ''): void
    {
        $fr = $fr !== '' ? $fr : "$slug-fr";
        $ja = $ja !== '' ? $ja : "$slug-ja";
        $this->write($slug, "🌐 **English** · [Français]($fr) · [日本語]($ja)", $body);
        $this->write($fr, "🌐 [English]($slug) · **Français** · [日本語]($ja)", $body);
        $this->write($ja, "🌐 [English]($slug) · [Français]($fr) · **日本語**", $body);
    }

    private function sidebar(string ...$slugs): void
    {
        $lines = ['**[Home](/Compte/Depot/wiki/Home)**', '', '**Group**'];
        foreach ($slugs as $s) {
            $lines[] = "- [$s](/Compte/Depot/wiki/" . rawurlencode($s) . ')';
        }
        file_put_contents($this->dir . '/_Sidebar.md', implode("\n", $lines) . "\n");
    }

    public function testACompleteWikiHasNoError(): void
    {
        $this->trio('Home');
        $this->trio('Guide', "Voir [Home](Home) et [Accueil](Home-fr) et [Ancre](Home#section) et <https://x.test>.\n1\n2\n3\n4\n5\n6");
        $this->sidebar('Guide');

        $result = (new WikiIntegrityChecker())->check($this->dir);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function testAListedPageMissingInALanguageIsAnError(): void
    {
        $this->trio('Home');
        $this->write('Guide', '🌐 **English** · [Français](Guide-fr) · [日本語](Guide-ja)');
        $this->write('Guide-fr', '🌐 [English](Guide) · **Français** · [日本語](Guide-ja)');
        $this->sidebar('Guide');

        $result = (new WikiIntegrityChecker())->check($this->dir);

        $this->assertContains('page manquante (ja) : Guide (Guide-ja)', $result['errors']);
        $this->assertNotContains('page manquante (fr) : Guide (Guide-fr)', $result['errors']);
    }

    public function testBrokenLinksAreReportedWithTheirPage(): void
    {
        $this->trio('Home');
        $this->trio('Guide', "[Cassé](Nulle-part) et [image](images/absente.png) et [ok](Home) et [image ok](images/presente.png).\n1\n2\n3\n4\n5\n6");
        $this->sidebar('Guide');
        file_put_contents($this->dir . '/images/presente.png', 'x');

        $errors = (new WikiIntegrityChecker())->check($this->dir)['errors'];

        $this->assertContains('lien cassé dans Guide : Nulle-part', $errors);
        $this->assertContains('lien cassé dans Guide : images/absente.png', $errors);
        $this->assertContains('lien cassé dans Guide-fr : Nulle-part', $errors);
        $this->assertNotContains('lien cassé dans Guide : Home', $errors);
        $this->assertNotContains('lien cassé dans Guide : images/presente.png', $errors);
    }

    public function testAbsoluteLinksOfTheWikiAreResolvedByPageName(): void
    {
        $this->trio('Home');
        $this->trio('Guide', "[Absolu](/Compte/Depot/wiki/Home) et [cassé](/Compte/Depot/wiki/Nulle-part).\n1\n2\n3\n4\n5\n6");
        $this->sidebar('Guide');

        $errors = (new WikiIntegrityChecker())->check($this->dir)['errors'];

        $this->assertContains('lien cassé dans Guide : /Compte/Depot/wiki/Nulle-part', $errors);
        $this->assertNotContains('lien cassé dans Guide : /Compte/Depot/wiki/Home', $errors);
    }

    public function testLinkLikeTextInCodeIsIgnored(): void
    {
        $this->trio('Home');
        $this->trio('Guide', "Exemple `[x](Inexistant)` et\n```\n[y](Aussi-inexistant)\n```\n1\n2\n3\n4\n5");
        $this->sidebar('Guide');

        $this->assertSame([], (new WikiIntegrityChecker())->check($this->dir)['errors']);
    }

    public function testAPageWithoutTheLanguageLineIsAnError(): void
    {
        $this->trio('Home');
        $this->trio('Guide');
        $this->write('Guide-fr', null, "Sans ligne de langue\n1\n2\n3\n4\n5\n6");
        $this->sidebar('Guide');

        $this->assertContains('ligne de choix de langue absente : Guide-fr', (new WikiIntegrityChecker())->check($this->dir)['errors']);
    }

    public function testAnUnlistedRealPageIsAWarningButARelayStubIsNot(): void
    {
        $this->trio('Home');
        $this->trio('Guide');
        $this->trio('Orpheline');
        $this->trio('Ancien', 'Cette page a été scindée : voir [Guide](Guide).');
        $this->sidebar('Guide');

        $result = (new WikiIntegrityChecker())->check($this->dir);

        $this->assertContains('page absente de la barre latérale (en) : Orpheline', $result['warnings']);
        $this->assertNotContains('page absente de la barre latérale (en) : Ancien', $result['warnings']);
        $this->assertSame([], $result['errors']);
    }

    public function testAnUnlistedPageMissingInALanguageIsAnError(): void
    {
        $this->trio('Home');
        $this->trio('Guide');
        $this->write('Seule-en-anglais', '🌐 **English** · [Français](Seule-fr) · [日本語](Seule-ja)');
        $this->sidebar('Guide');

        $errors = (new WikiIntegrityChecker())->check($this->dir)['errors'];

        $this->assertContains('traduction manquante (fr) : Seule-en-anglais', $errors);
        $this->assertContains('traduction manquante (ja) : Seule-en-anglais', $errors);
    }

    public function testATranslatedPageWithoutItsEnglishPageIsAnError(): void
    {
        $this->trio('Home');
        $this->trio('Guide');
        $this->write('Orpheline-fr', '🌐 [English](Disparue) · **Français** · [日本語](Orpheline-ja)');
        $this->sidebar('Guide');

        $this->assertContains('page traduite sans page anglaise (fr) : Orpheline-fr', (new WikiIntegrityChecker())->check($this->dir)['errors']);
    }

    public function testAMissingSidebarIsReported(): void
    {
        $this->assertSame(['_Sidebar.md introuvable'], (new WikiIntegrityChecker())->check($this->dir)['errors']);
    }

    public function testJapaneseTranslatedFileNamesAreChecked(): void
    {
        $this->trio('Home');
        $this->trio('Guide', self::BODY, 'Guide-fr', 'ガイド');
        $this->sidebar('Guide');

        $result = (new WikiIntegrityChecker())->check($this->dir);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function testAMissingJapaneseFileIsReportedWithItsName(): void
    {
        $this->trio('Home');
        $this->write('Guide', '🌐 **English** · [Français](Guide-fr) · [日本語](ガイド)');
        $this->write('Guide-fr', '🌐 [English](Guide) · **Français** · [日本語](ガイド)');
        $this->sidebar('Guide');

        $errors = (new WikiIntegrityChecker())->check($this->dir)['errors'];

        $this->assertContains('page manquante (ja) : Guide (ガイド)', $errors);
    }
}
