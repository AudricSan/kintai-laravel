<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Footer applicatif et modale « Signaler un problème ».
 *
 * Régressions couvertes :
 * - la modale empruntait les classes .fb-overlay/.fb-modal, dont le CSS est livré par le bundle
 *   feedback : sans ce bundle, elle s'affichait en clair sous le footer en permanence ;
 * - la modale de feedback (code de bundle resté dans le Core) construisait l'URL d'une route
 *   inexistante sur les pages hors AuthMiddleware (pages légales) → erreur 500.
 */
final class FooterSupportModalsTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../resources/views';

    protected function setUp(): void
    {
        $router = kintai_test_registrar();
        foreach ([
            'home' => '/', 'employee.dashboard' => '/employee', 'auth.login' => '/login',
            'docs.index' => '/docs', 'legal.mentions' => '/legal', 'privacy' => '/privacy',
            'legal.terms' => '/terms', 'legal.license' => '/license', 'admin.update' => '/admin/update',
        ] as $name => $path) {
            $router->get($path, ['X', 'y'], name: $name);
        }
        $router->post('/support/report-issue', ['X', 'y'], name: 'support.report_issue');
    }

    protected function tearDown(): void
    {
        unset($_GET['ri_error'], $_GET['ri_success']);
    }

    private function renderModal(): string
    {
        $BASE_URL = '';
        ob_start();
        echo kintai_test_views(self::VIEWS)->make('layout.partials.report-issue-modal', get_defined_vars())->render();
        return (string) ob_get_clean();
    }

    private function renderFooter(): string
    {
        $BASE_URL = '';
        $auth_user = ['id' => 1, 'is_admin' => 0];
        $isManager = false;
        ob_start();
        echo kintai_test_views(self::VIEWS)->make('layout.partials._footer', get_defined_vars())->render();
        return (string) ob_get_clean();
    }

    public function testReportIssueModalUsesCoreModalComponentAndStartsClosed(): void
    {
        $html = $this->renderModal();

        $this->assertMatchesRegularExpression('/id="report-issue-modal" class="modal"/', $html);
        $this->assertStringContainsString('modal__dialog', $html);
        $this->assertStringNotContainsString('fb-', $html);
    }

    public function testReportIssueModalOpensItselfAfterASubmission(): void
    {
        $_GET['ri_error'] = 'empty';

        $this->assertStringContainsString('class="modal open"', $this->renderModal());
    }

    public function testFooterOpensReportIssueModalAndHasNoFeedbackButton(): void
    {
        $html = $this->renderFooter();

        $this->assertStringContainsString('data-on-click="openModal" data-args=\'["report-issue-modal"]\'', $html);
        $this->assertStringNotContainsString('fbOpen', $html);
    }

    public function testCoreLayoutNoLongerShipsTheFeedbackModal(): void
    {
        $this->assertFileDoesNotExist(self::VIEWS . '/layout/partials/feedback-modal.php');
        $this->assertFileDoesNotExist(self::VIEWS . '/layout/partials/feedback-modal.blade.php');
        $this->assertStringNotContainsString('feedback', (string) file_get_contents(self::VIEWS . '/layout/app.blade.php'));
    }
}
