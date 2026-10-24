<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller;

use kintai\Core\Container;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Router;
use kintai\Core\Services\TranslationService;
use kintai\Core\Services\WikiContentService;
use kintai\Core\Services\WikiSyncService;
use kintai\UI\Controller\Web\DocsController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Depuis que /docs n'affiche plus de grille de sélection de langue, l'entrée
 * (index()) doit rediriger directement vers la page d'accueil du wiki dans
 * la langue de l'utilisateur si elle est disponible localement, ou afficher
 * un message "indisponible" sinon.
 */
final class DocsControllerTest extends TestCase
{
    private string $tmpWiki;
    private string $tmpViews;

    protected function setUp(): void
    {
        $this->tmpWiki = sys_get_temp_dir() . '/kintai_docsctrl_wiki_' . uniqid();
        mkdir($this->tmpWiki . '/fr', 0755, true);
        mkdir($this->tmpWiki . '/ja', 0755, true);

        $this->tmpViews = sys_get_temp_dir() . '/kintai_docsctrl_views_' . uniqid();
        mkdir($this->tmpViews . '/docs', 0755, true);
        mkdir($this->tmpViews . '/layout', 0755, true);

        file_put_contents(
            $this->tmpViews . '/docs/unavailable.php',
            'UNAVAILABLE|<?= $locale ?>|<?= $githubWikiUrl ?>|<?= $isOwner ? "owner" : "guest" ?>'
        );
        file_put_contents(
            $this->tmpViews . '/docs/show.php',
            'SHOW|<?= $lang ?>|<?= $page ?>|<?= $isOwner ? "owner" : "guest" ?>'
        );
        file_put_contents($this->tmpViews . '/layout/app.php', '<?= $content ?>');

        $router = new Router();
        $router->get('/docs', [DocsController::class, 'index'], name: 'docs.index');
        $router->get('/docs/{lang}/{page}', [DocsController::class, 'show'], name: 'docs.show');
        $router->post('/docs/sync', [DocsController::class, 'sync'], name: 'docs.sync');
        Container::getInstance()->instance(Router::class, $router);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpWiki);
        $this->removeDir($this->tmpViews);
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

    private function seedFixture(): void
    {
        $this->writeWikiFile('_Sidebar.md', "**[Home](Home)**\n");
        // Wiki à plat : chaque langue a son fichier à la racine, nommé dans la ligne de langue de la page anglaise.
        $this->writeWikiFile('Home.md', "# Home\n\n🌐 **English** · [Français](Accueil) · [日本語](ホーム)\n\nEnglish body.");
        $this->writeWikiFile('Accueil.md', "# Accueil\n\n🌐 [English](Home) · **Français** · [日本語](ホーム)\n\nTexte français.");
        // ホーム.md volontairement absent : simule une traduction non disponible.
    }

    private function translationService(string $locale): TranslationService
    {
        $translationRepo = $this->createStub(TranslationRepositoryInterface::class);
        $translationRepo->method('findByLocale')->willReturn([]);

        $languageRepo = $this->createStub(LanguageRepositoryInterface::class);
        $languageRepo->method('findDefault')->willReturn(['code' => 'en']);

        $service = new TranslationService($translationRepo, $languageRepo);
        $service->setLocale($locale);

        return $service;
    }

    private function controller(string $locale): DocsController
    {
        return new DocsController(
            new ViewRenderer($this->tmpViews),
            $this->translationService($locale),
            new WikiContentService($this->tmpWiki),
            new WikiSyncService($this->tmpWiki),
        );
    }

    // -------------------------------------------------------------------------
    // index() — langue disponible localement : redirection directe
    // -------------------------------------------------------------------------

    public function testIndexRedirectsToWikiHomeWhenAvailableInCurrentLocale(): void
    {
        $this->seedFixture();

        $resp = $this->controller('fr')->index(new Request());

        $this->assertSame(302, $resp->status());
    }

    public function testIndexRedirectTargetsCorrectLangAndPage(): void
    {
        $this->seedFixture();

        $resp = $this->controller('fr')->index(new Request());

        // base_url() dépend de SCRIPT_NAME (variable en CLI) : on ne vérifie que le suffixe stable.
        $this->assertStringEndsWith('docs/fr/Home', $this->locationOf($resp));
    }

    private function locationOf(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');
        $ref->setAccessible(true);

        return $ref->getValue($response)['Location'] ?? '';
    }

    // -------------------------------------------------------------------------
    // index() — langue non disponible localement : message + lien GitHub
    // -------------------------------------------------------------------------

    public function testIndexRendersUnavailableViewWhenLocaleNotCachedLocally(): void
    {
        $this->seedFixture();

        // 'ja/Home.md' n'existe pas dans la fixture : simule une traduction manquante.
        $resp = $this->controller('ja')->index(new Request());

        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('UNAVAILABLE|ja|', $resp->body());
    }

    public function testIndexUnavailableViewIncludesGithubLink(): void
    {
        $this->seedFixture();

        $resp = $this->controller('ja')->index(new Request());

        $this->assertStringContainsString('https://github.com/', $resp->body());
        $this->assertStringContainsString('/wiki/' . rawurlencode('ホーム'), $resp->body());
    }

    public function testIndexUnavailableViewReflectsOwnerStatus(): void
    {
        $this->seedFixture();

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => 1]);

        $resp = $this->controller('ja')->index($req);

        $this->assertStringContainsString('|owner', $resp->body());
    }

    public function testIndexUnavailableViewReflectsNonOwnerStatus(): void
    {
        $this->seedFixture();

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => 0]);

        $resp = $this->controller('ja')->index($req);

        $this->assertStringContainsString('|guest', $resp->body());
    }

    public function testIndexRendersUnavailableViewWhenWikiEntirelyUnconfigured(): void
    {
        // Pas de seedFixture() : .wiki/ existe (créé dans setUp) mais sans _Sidebar.md.
        $resp = $this->controller('fr')->index(new Request());

        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('UNAVAILABLE|fr|', $resp->body());
    }

    // -------------------------------------------------------------------------
    // show() — inchangé dans son comportement, gagne juste isOwner
    // -------------------------------------------------------------------------

    public function testShowPassesOwnerStatusToView(): void
    {
        $this->seedFixture();

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => 1]);
        $req->setRouteParams(['lang' => 'fr', 'page' => 'Home']);

        $resp = $this->controller('fr')->show($req);

        $this->assertStringContainsString('SHOW|fr|Home|owner', $resp->body());
    }

    // -------------------------------------------------------------------------
    // sync() — inchangé, réservé au propriétaire
    // -------------------------------------------------------------------------

    // Le rejet non-Owner est désormais couvert par OwnerOnlyMiddlewareTest
    // (route-level, RBAC-V2) — le contrôleur ne se garde plus lui-même.
}
