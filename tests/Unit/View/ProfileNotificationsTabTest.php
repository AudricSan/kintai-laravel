<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\View;

use kintai\Contract\Extension\NotificationType;
use kintai\Core\Container;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Onglet « Notifications » du profil, rendu avec la vraie vue : grille par catégorie, cases rattachées au formulaire
 * unique par form= (pas de <form> imbriqué), heures silencieuses, et aucun attribut interdit par la CSP.
 */
final class ProfileNotificationsTabTest extends TestCase
{
    protected function setUp(): void
    {
        session()->put('_token', 'test-token');
        $router = kintai_test_registrar();
        $router->get('/profile', ['X', 'y'], name: 'profile');
        $router->post('/profile/notifications', ['X', 'y'], name: 'profile.notifications');
    }

    protected function tearDown(): void
    {
        session()->forget('_token');
    }

    private function render(array $grid, ?array $quiet, array $extra = []): string
    {
        return (new ViewRenderer(__DIR__ . '/../../../app/UI/View'))->render('auth.profile', $extra + [
            'title'                => 'Profil',
            'user'                 => ['id' => 9],
            'has_default_password' => false,
            'tab'                  => 'notifications',
            'notif_grid'           => $grid,
            'quiet_hours'          => $quiet,
        ]);
    }

    public function testRendersGridByCategoryWithBoxesBoundToTheSingleForm(): void
    {
        $html = $this->render([
            'planning' => [
                ['type' => new NotificationType('shift_assigned', '📅', 'planning', true, true), 'push' => true, 'email' => false],
            ],
            'messages' => [
                ['type' => new NotificationType('acme_news', '📰', 'messages', true, false), 'push' => false, 'email' => false],
            ],
        ], ['enabled' => true, 'start' => '22:00', 'end' => '07:00']);

        $this->assertMatchesRegularExpression('#action="[^"]*/profile/notifications"#', $html);
        $this->assertSame(1, substr_count($html, 'id="notif-prefs-form"'));
        // Cases push/e-mail de chaque type + heures silencieuses : toutes rattachées au formulaire.
        $this->assertSame(1, preg_match_all('/<input[^>]*form="notif-prefs-form"[^>]*name="push\[\]"[^>]*value="shift_assigned"[^>]*checked/s', $html));
        $this->assertSame(0, preg_match_all('/name="push\[\]"[^>]*value="acme_news"[^>]*checked/s', $html), 'push décoché de acme_news');
        $this->assertSame(4, preg_match_all('/name="(?:push|email)\[\]"/', $html));
        // Chaque ligne affichée est déclarée au serveur (shown[]) : seules ces lignes seront enregistrées.
        $this->assertSame(1, preg_match_all('/<input type="hidden" form="notif-prefs-form" name="shown\[\]" value="shift_assigned">/', $html));
        $this->assertSame(1, preg_match_all('/<input type="hidden" form="notif-prefs-form" name="shown\[\]" value="acme_news">/', $html));
        $this->assertSame(2, preg_match_all('/name="shown\[\]"/', $html), 'une par ligne de la grille');
        $this->assertMatchesRegularExpression('/name="quiet_enabled"[^>]*checked/s', $html);
        $this->assertStringContainsString('value="22:00"', $html);
        $this->assertStringContainsString('value="07:00"', $html);
        // La colonne cloche est affichée cochée et grisée.
        $this->assertSame(2, substr_count($html, 'checked disabled'));
    }

    public function testQuietHoursOffByDefaultAndNoGrid(): void
    {
        $html = $this->render([], ['enabled' => false, 'start' => '22:00', 'end' => '07:00']);

        $this->assertDoesNotMatchRegularExpression('/name="quiet_enabled"[^>]*checked/s', $html);
        $this->assertStringNotContainsString('<table class="data-table notif-prefs-table">', $html);
    }

    public function testDeviceCardShowsEveryStateBlockWhenWebPushIsAvailable(): void
    {
        $html = $this->render([], null, ['push_web_config' => ['vapid_key' => 'public-key']]);

        foreach (['unsupported', 'ios', 'denied', 'disabled', 'enabled', 'error'] as $state) {
            $this->assertStringContainsString('id="push-status-' . $state . '"', $html, $state);
        }
        $this->assertStringContainsString('id="push-enable-btn"', $html);
        $this->assertStringContainsString('id="push-disable-btn"', $html);
    }

    public function testDeviceCardSaysWhenWebPushIsNotAvailable(): void
    {
        $html = $this->render([], null, ['push_web_config' => []]);

        $this->assertStringNotContainsString('id="push-enable-btn"', $html);
        $this->assertStringContainsString('push_not_configured', $html);
    }

    public function testWarnsThatEmailIsNotSetUpOnlyWhenItIsNot(): void
    {
        $grid = ['planning' => [['type' => new NotificationType('shift_assigned', '📅', 'planning'), 'push' => true, 'email' => true]]];

        $this->assertStringContainsString('notif_email_unavailable', $this->render($grid, null, ['email_available' => false]));
        $this->assertStringContainsString('notif_email_unavailable', $this->render($grid, null));
        $this->assertStringNotContainsString('notif_email_unavailable', $this->render($grid, null, ['email_available' => true]));
    }

    public function testRespectsCspAndNoInlineStyle(): void
    {
        $html = $this->render([
            'planning' => [['type' => new NotificationType('shift_assigned', '📅', 'planning'), 'push' => true, 'email' => false]],
        ], null);

        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html);
        $this->assertStringContainsString('data-on-click="@toggleChecks"', $html);
    }
}
