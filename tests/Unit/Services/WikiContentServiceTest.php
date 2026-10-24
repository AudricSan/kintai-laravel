<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Container;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Router;
use kintai\Core\Services\WikiContentService;
use kintai\UI\Controller\Web\DocsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Fixture calquée sur la vraie structure du wiki GitHub du projet :
 * pages anglaises à la racine, traductions sous fr/ et ja/, navigation
 * définie par _Sidebar.md, liens internes en chemins relatifs sans ".md".
 */
final class WikiContentServiceTest extends TestCase
{
    private string $tmpWiki;

    protected function setUp(): void
    {
        $this->tmpWiki = sys_get_temp_dir() . '/kintai_wiki_test_' . uniqid();
        mkdir($this->tmpWiki, 0755, true);

        $router = new Router();
        $router->get('/docs/{lang}/{page}', [DocsController::class, 'show'], name: 'docs.show');
        Container::getInstance()->instance(Router::class, $router);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpWiki);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeWikiFile(string $relativePath, string $content): void
    {
        file_put_contents($this->tmpWiki . '/' . $relativePath, $content);
    }

    /** Wiki à plat : l'anglais et ses traductions sont à la racine, chaque langue sous son propre nom. */
    private function seedFixture(): void
    {
        $this->writeWikiFile('_Sidebar.md', <<<MD
        **[Home](/AudricSan/Kintai/wiki/Home)**

        🌐 [English](/AudricSan/Kintai/wiki/Home) · [Français](/AudricSan/Kintai/wiki/Accueil) · [日本語](/AudricSan/Kintai/wiki/%E3%83%9B%E3%83%BC%E3%83%A0)

        **Getting Started**
        - [Installation](/AudricSan/Kintai/wiki/Installation)
        MD);

        $this->writeWikiFile('Home.md', <<<MD
        # Kintai Wiki

        🌐 **English** · [Français](Accueil) · [日本語](ホーム)

        See [Installation](Installation), an [external link](https://example.com),
        an [anchor](#section), and a [broken link](Nonexistent-Page).
        MD);

        $this->writeWikiFile('Installation.md', "# Installation\n\n🌐 **English** · [Français](Installer-Kintai) · [日本語](インストール)\n\nContent.");

        $this->writeWikiFile('Accueil.md', <<<MD
        # Wiki Kintai

        🌐 [English](Home) · **Français** · [日本語](ホーム)

        Voir [Installer](Installer-Kintai), [English](Home) ou [日本語](ホーム).
        MD);

        $this->writeWikiFile('Installer-Kintai.md', "# Installation FR\n\n🌐 [English](Installation) · **Français** · [日本語](インストール)\n\nContenu.");

        // Volontairement absent : インストール.md (teste le fallback de titre + indisponibilité)
        $this->writeWikiFile('ホーム.md', <<<MD
        # Kintaiウィキ

        🌐 [English](Home) · [Français](Accueil) · **日本語**
        MD);
    }
    private function service(): WikiContentService
    {
        return new WikiContentService($this->tmpWiki);
    }

    // -------------------------------------------------------------------------
    // isConfigured() / supportedLangs()
    // -------------------------------------------------------------------------

    public function testIsConfiguredTrueWhenDirExists(): void
    {
        $this->assertTrue($this->service()->isConfigured());
    }

    public function testIsConfiguredFalseWhenDirMissing(): void
    {
        $service = new WikiContentService($this->tmpWiki . '/does-not-exist');
        $this->assertFalse($service->isConfigured());
    }

    public function testSupportedLangsContainsFrEnJa(): void
    {
        $langs = WikiContentService::supportedLangs();
        $this->assertContains('fr', $langs);
        $this->assertContains('en', $langs);
        $this->assertContains('ja', $langs);
    }

    // -------------------------------------------------------------------------
    // firstPage()
    // -------------------------------------------------------------------------

    public function testFirstPageReturnsHomeFromTopOfSidebar(): void
    {
        $this->seedFixture();
        $this->assertSame('Home', $this->service()->firstPage());
    }

    public function testFirstPageReturnsNullWithoutSidebar(): void
    {
        $this->assertNull($this->service()->firstPage());
    }

    // -------------------------------------------------------------------------
    // tableOfContents()
    // -------------------------------------------------------------------------

    public function testTableOfContentsHasTopGroupAndNamedGroup(): void
    {
        $this->seedFixture();
        $toc = $this->service()->tableOfContents('en');

        $this->assertCount(2, $toc);
        $this->assertNull($toc[0]['label']);
        // Sans TranslationService lié au container, __() retombe sur la clé brute.
        $this->assertSame('docs_group_getting_started', $toc[1]['label']);
    }

    public function testTableOfContentsTopGroupContainsHome(): void
    {
        $this->seedFixture();
        $toc = $this->service()->tableOfContents('en');

        $this->assertSame('Home', $toc[0]['items'][0]['slug']);
        $this->assertSame('Kintai Wiki', $toc[0]['items'][0]['title']);
        $this->assertTrue($toc[0]['items'][0]['available']);
    }

    public function testTableOfContentsUsesFrenchTitleForFrenchLang(): void
    {
        $this->seedFixture();
        $toc = $this->service()->tableOfContents('fr');

        $this->assertSame('Wiki Kintai', $toc[0]['items'][0]['title']);
    }

    public function testTableOfContentsFallsBackToEnglishTitleWhenTranslationMissing(): void
    {
        $this->seedFixture();
        $toc = $this->service()->tableOfContents('ja');

        // インストール.md n'existe pas : titre replié sur la version anglaise.
        $this->assertSame('Installation', $toc[1]['items'][0]['title']);
        $this->assertFalse($toc[1]['items'][0]['available']);
    }

    public function testTableOfContentsEmptyWithoutSidebar(): void
    {
        $this->assertSame([], $this->service()->tableOfContents('en'));
    }

    public function testTableOfContentsItemsIncludeGithubUrl(): void
    {
        $this->seedFixture();
        $toc = $this->service()->tableOfContents('fr');

        $this->assertSame(
            'https://github.com/AudricSan/Kintai/wiki/Accueil',
            $toc[0]['items'][0]['github_url']
        );
    }

    // -------------------------------------------------------------------------
    // githubWikiUrl() — accès en ligne indépendant de la disponibilité locale
    // -------------------------------------------------------------------------

    public function testGithubWikiUrlForEnglishHasNoLangPrefix(): void
    {
        $this->assertSame(
            'https://github.com/AudricSan/Kintai/wiki/Home',
            $this->service()->githubWikiUrl('en', 'Home')
        );
    }

    public function testGithubWikiUrlWithoutTranslationKeepsTheEnglishName(): void
    {
        // Wiki à plat : plus de préfixe de langue ; sans traduction déclarée, le nom anglais est repris.
        $this->assertSame(
            'https://github.com/AudricSan/Kintai/wiki/Installation',
            $this->service()->githubWikiUrl('fr', 'Installation')
        );
    }

    public function testGithubWikiUrlUsesTheTranslatedFileName(): void
    {
        $this->seedFixture();

        $this->assertSame('https://github.com/AudricSan/Kintai/wiki/Installer-Kintai', $this->service()->githubWikiUrl('fr', 'Installation'));
        $this->assertSame('https://github.com/AudricSan/Kintai/wiki/' . rawurlencode('インストール'), $this->service()->githubWikiUrl('ja', 'Installation'));
    }

    public function testGithubWikiUrlDefaultsToHomeWithoutSlug(): void
    {
        $this->assertSame(
            'https://github.com/AudricSan/Kintai/wiki/Home',
            $this->service()->githubWikiUrl('en')
        );
    }

    public function testGithubWikiUrlDefaultsToHomeForInvalidSlug(): void
    {
        $this->assertSame(
            'https://github.com/AudricSan/Kintai/wiki/Home',
            $this->service()->githubWikiUrl('fr', '../../../etc/passwd')
        );
    }

    public function testGithubWikiUrlAvailableWithoutLocalWikiClone(): void
    {
        // Ne requiert aucun .wiki/ sur le disque : contrairement à render()/pageExists(),
        // cette méthode ne fait aucun accès au système de fichiers.
        $service = new WikiContentService($this->tmpWiki . '/never-cloned');

        $this->assertSame(
            'https://github.com/AudricSan/Kintai/wiki/Home',
            $service->githubWikiUrl('en')
        );
    }

    // -------------------------------------------------------------------------
    // pageExists()
    // -------------------------------------------------------------------------

    public function testPageExistsTrueForKnownPage(): void
    {
        $this->seedFixture();
        $this->assertTrue($this->service()->pageExists('en', 'Home'));
        $this->assertTrue($this->service()->pageExists('fr', 'Installation'));
    }

    public function testPageExistsFalseForMissingTranslation(): void
    {
        $this->seedFixture();
        $this->assertFalse($this->service()->pageExists('ja', 'Installation'));
    }

    public function testPageExistsFalseForUnknownLang(): void
    {
        $this->seedFixture();
        $this->assertFalse($this->service()->pageExists('de', 'Home'));
    }

    #[DataProvider('invalidSlugProvider')]
    public function testPageExistsFalseForInvalidOrTraversalSlug(string $slug): void
    {
        $this->seedFixture();
        $this->assertFalse($this->service()->pageExists('en', $slug));
    }

    public static function invalidSlugProvider(): array
    {
        return [
            'parent traversal'   => ['../../../etc/passwd'],
            'nested path'        => ['fr/Home'],
            'meta sidebar page'  => ['_Sidebar'],
            'empty slug'         => [''],
        ];
    }

    // -------------------------------------------------------------------------
    // render()
    // -------------------------------------------------------------------------

    public function testRenderThrowsNotFoundForUnknownPage(): void
    {
        $this->seedFixture();
        $this->expectException(NotFoundException::class);
        $this->service()->render('en', 'Does-Not-Exist');
    }

    public function testRenderThrowsNotFoundForPathTraversalAttempt(): void
    {
        $this->seedFixture();
        $this->expectException(NotFoundException::class);
        $this->service()->render('en', '../../../../etc/passwd');
    }

    public function testRenderConvertsMarkdownToHtml(): void
    {
        $this->seedFixture();
        $html = $this->service()->render('en', 'Installation');

        $this->assertStringContainsString('<h1>Installation</h1>', $html);
        $this->assertStringContainsString('<p>Content.</p>', $html);
    }

    public function testRenderStripsLanguageSwitchLine(): void
    {
        $this->seedFixture();
        $html = $this->service()->render('en', 'Home');

        $this->assertStringNotContainsString('🌐', $html);
    }

    public function testRenderRewritesSameLanguageInternalLink(): void
    {
        $this->seedFixture();
        $html = $this->service()->render('en', 'Home');

        $this->assertStringContainsString('docs/en/Installation"', $html);
    }

    public function testRenderKeepsExternalLinkUnchanged(): void
    {
        $this->seedFixture();
        $html = $this->service()->render('en', 'Home');

        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function testRenderKeepsAnchorLinkUnchanged(): void
    {
        $this->seedFixture();
        $html = $this->service()->render('en', 'Home');

        $this->assertStringContainsString('href="#section"', $html);
    }

    public function testRenderDegradesBrokenInternalLinkToPlainText(): void
    {
        $this->seedFixture();
        $html = $this->service()->render('en', 'Home');

        $this->assertStringContainsString('broken link', $html);
        $this->assertStringNotContainsString('Nonexistent-Page', $html);
    }

    public function testRenderResolvesAnEnglishLinkFromATranslatedPage(): void
    {
        $this->seedFixture();
        // Depuis Accueil.md : [English](Home) doit pointer vers /docs/en/Home
        $html = $this->service()->render('fr', 'Home');

        $this->assertStringContainsString('docs/en/Home"', $html);
    }

    public function testRenderResolvesCrossLanguageSiblingLink(): void
    {
        $this->seedFixture();
        // Depuis Accueil.md : [日本語](ホーム) doit pointer vers /docs/ja/Home (clé anglaise)
        $html = $this->service()->render('fr', 'Home');

        $this->assertStringContainsString('docs/ja/Home"', $html);
    }

    public function testRenderResolvesATranslatedNameToTheEnglishKey(): void
    {
        $this->seedFixture();
        // Depuis Accueil.md : [Installer](Installer-Kintai) doit pointer vers /docs/fr/Installation
        $html = $this->service()->render('fr', 'Home');

        $this->assertStringContainsString('docs/fr/Installation"', $html);
    }
    // -------------------------------------------------------------------------
    // Noms de fichiers traduits (fr/ et ja/), déclarés par la ligne de langue de la page anglaise
    // -------------------------------------------------------------------------

    private function seedLocalizedFixture(): void
    {
        $this->writeWikiFile('_Sidebar.md', "**[Home](/AudricSan/Kintai/wiki/Home)**\n\n**Admin**\n- [Backups](/AudricSan/Kintai/wiki/Backups)\n");
        $this->writeWikiFile('Home.md', "# Home\n\n🌐 **English** · [Français](Accueil) · [日本語](ホーム)\n\nSee [Backups](Backups).");
        $this->writeWikiFile('Backups.md', "# Backups\n\n🌐 **English** · [Français](Sauvegardes) · [日本語](バックアップ)\n");
        $this->writeWikiFile('Accueil.md', "# Accueil\n\n🌐 [English](Home) · **Français** · [日本語](ホーム)\n\nVoir [Sauvegardes](Sauvegardes) et [Backups](Backups).");
        $this->writeWikiFile('Sauvegardes.md', "# Sauvegardes\n\n🌐 [English](Backups) · **Français** · [日本語](バックアップ)\n");
        $this->writeWikiFile('ホーム.md', "# ホーム\n\n🌐 [English](Home) · [Français](Accueil) · **日本語**\n\n[バックアップ](バックアップ)を参照。");
        $this->writeWikiFile('バックアップ.md', "# バックアップ\n\n🌐 [English](Backups) · [Français](Sauvegardes) · **日本語**\n");
    }

    public function testTheLanguageLineGivesTheOwnLanguageAndTheOtherVersions(): void
    {
        $line = WikiContentService::languageLine("# T\n\n🌐 [English](Backups) · **Français** · [日本語](バックアップ)\n");

        $this->assertSame('fr', $line['own']);
        $this->assertSame(['en' => 'Backups', 'ja' => 'バックアップ'], $line['links']);
    }

    public function testLocalizedSlugsAreReadFromAnEnglishPage(): void
    {
        $slugs = WikiContentService::localizedSlugsFromPage("# T\n\n🌐 **English** · [Français](Sauvegardes) · [日本語](バックアップ)\n");

        $this->assertSame(['fr' => 'Sauvegardes', 'ja' => 'バックアップ'], $slugs);
    }

    public function testLegacyLanguageLinksWithAFolderAreStillUnderstood(): void
    {
        $slugs = WikiContentService::localizedSlugsFromPage("# T\n\n🌐 **English** · [Français](fr/Sauvegardes) · [日本語](ja/バックアップ)\n");

        $this->assertSame(['fr' => 'Sauvegardes', 'ja' => 'バックアップ'], $slugs);
    }

    public function testAPageWithoutLanguageLineHasNoTranslations(): void
    {
        $this->assertSame([], WikiContentService::localizedSlugsFromPage("# T\n\nSans ligne de langue."));
    }

    public function testJapaneseAndAccentedSlugsAreValidButTraversalIsNot(): void
    {
        $this->assertTrue(WikiContentService::isValidSlug('バックアップ'));
        $this->assertTrue(WikiContentService::isValidSlug('Sauvegardes-et-mises-à-jour'));
        $this->assertFalse(WikiContentService::isValidSlug('../バックアップ'));
        $this->assertFalse(WikiContentService::isValidSlug('_Sidebar'));
    }

    public function testTheSidebarAcceptsAbsoluteAndEncodedLinks(): void
    {
        $groups = WikiContentService::parseSidebarMarkdown("**Admin**\n- [Backups](/AudricSan/Kintai/wiki/Backups)\n- [Photo](/AudricSan/Kintai/wiki/" . rawurlencode('バックアップ') . ")\n- [Rel](Installation)\n");

        $this->assertSame(['Admin' => ['Backups', 'バックアップ', 'Installation']], $groups);
    }

    public function testPageKeysStayEnglishWhileFilesAreRead(): void
    {
        $this->seedLocalizedFixture();

        $this->assertTrue($this->service()->pageExists('fr', 'Backups'));
        $this->assertTrue($this->service()->pageExists('ja', 'Backups'));
        $this->assertStringContainsString('<h1>バックアップ</h1>', $this->service()->render('ja', 'Backups'));
    }

    public function testTableOfContentsUsesTheTranslatedTitle(): void
    {
        $this->seedLocalizedFixture();
        $items = $this->service()->tableOfContents('ja')[1]['items'];

        $this->assertSame('Backups', $items[0]['slug']);
        $this->assertSame('バックアップ', $items[0]['title']);
        $this->assertTrue($items[0]['available']);
    }

    public function testLinksBetweenTranslatedPagesResolveToTheEnglishKey(): void
    {
        $this->seedLocalizedFixture();

        $fr = $this->service()->render('fr', 'Home');
        $ja = $this->service()->render('ja', 'Home');

        $this->assertStringContainsString('docs/fr/Backups"', $fr);
        $this->assertStringContainsString('docs/en/Backups"', $fr);
        $this->assertStringContainsString('docs/ja/Backups"', $ja);
    }

    public function testGithubUrlUsesTheEncodedTranslatedFileName(): void
    {
        $this->seedLocalizedFixture();
        $url = $this->service()->githubWikiUrl('ja', 'Backups');

        $this->assertStringEndsWith('/wiki/' . rawurlencode('バックアップ'), $url);
        $this->assertStringEndsWith('/wiki/Sauvegardes', $this->service()->githubWikiUrl('fr', 'Backups'));
    }
}