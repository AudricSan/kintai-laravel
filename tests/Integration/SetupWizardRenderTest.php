<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Container\Container;
use App\Services\SetupWizard;
use App\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

/** Chaque écran de l'assistant se rend sans erreur, avec la barre de progression et le bon formulaire. */
final class SetupWizardRenderTest extends TestCase
{
    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->view = new ViewRenderer(BASE_PATH . '/resources/views');
        $router = kintai_test_registrar();
        foreach ([
            'admin.setup', 'admin.setup.license', 'admin.setup.bundles', 'admin.setup.questions', 'admin.setup.step', 'admin.setup.finish', 'admin.owner_settings',
            'admin.stores', 'admin.mail_test', 'admin.notifications_settings', 'admin.backup', 'admin.bundles.market', 'admin.license',
        ] as $i => $name) {
            $router->post('/r' . $i . ($name === 'admin.setup.step' ? '/{step}' : ''), [\stdClass::class, 'x'], name: $name);
        }
    }

    /** @param array<string, mixed> $extra */
    private function render(string $step, array $extra = []): string
    {
        $screen = $step === 'bundles_result' ? 'bundles' : $step;
        return $this->view->renderPartial('system.setup', $extra + [
            'BASE_URL'  => '',
            'title'     => 'Setup',
            'step'      => $step,
            'steps'     => SetupWizard::STEPS,
            'progress'  => ['organisation' => 'done', 'store' => 'skipped', 'mail' => null, 'notifications' => null, 'operations' => null, 'bundles' => null, 'license' => null],
            'position'  => SetupWizard::position($screen),
            'total'     => count(SetupWizard::STEPS),
            'previous'  => SetupWizard::previousStep($screen),
            'next'      => SetupWizard::nextStep($screen),
            'skippable' => in_array($screen, SetupWizard::SKIPPABLE, true),
            'error'     => null,
            'errors'    => [],
        ]);
    }

    public function testProgressBarShowsEveryStepWithItsState(): void
    {
        $html = $this->render('mail', ['stepData' => [
            'driver' => 'native', 'configured' => false, 'from' => '', 'from_name' => 'Kintai', 'host' => '', 'port' => '465', 'username' => '',
            'has_password' => false, 'encryption' => 'ssl', 'encryptions' => ['ssl', 'tls', 'none'],
        ]]);

        $this->assertSame(8, substr_count($html, 'setup-steps__item"') + substr_count($html, 'setup-steps__item '));
        $this->assertStringContainsString('setup-steps__item setup-steps__item--done', $html);
        $this->assertStringContainsString('setup-steps__item--skipped', $html);
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringContainsString('name="driver"', $html);
        $this->assertStringContainsString('name="action" value="test"', $html);
        $this->assertStringContainsString('name="skip" value="1"', $html, 'chaque étape facultative peut être passée');
    }

    public function testEveryFormStepRenders(): void
    {
        $data = [
            'organisation' => ['name' => '', 'support_email' => '', 'public_url' => '', 'public_url_forced' => false, 'public_url_suggestion' => 'http://127.0.0.7'],
            'store' => ['existing' => ['Paris'], 'timezones' => ['UTC', 'Asia/Tokyo'], 'currencies' => ['EUR', 'JPY'], 'defaults' => ['timezone' => 'UTC', 'currency' => 'EUR', 'type' => 'retail']],
            'notifications' => ['status' => [
                'web_push' => ['available' => true, 'has_keys' => true, 'missing_extensions' => [], 'subscriptions' => 0],
                'email' => ['available' => false, 'configured' => false, 'driver' => 'native', 'has_public_url' => false],
                'mobile' => ['available' => false, 'enabled' => false, 'has_project' => false, 'has_credentials' => false],
            ]],
            'operations' => ['backup_auto' => true, 'backup_keep' => 10, 'update_channel' => 'release', 'channels' => ['release', 'beta', 'alpha'],
                'cron_command' => 'php /srv/scripts/cron.php', 'cron_url' => 'https://exemple.fr/cron/run/backup', 'cron_token_command' => 'php scripts/create-cron-token.php'],
        ];
        $needle = ['organisation' => 'name="support_email"', 'store' => 'name="timezone"', 'notifications' => 'name="send_test"', 'operations' => 'name="update_channel"'];

        foreach ($data as $step => $stepData) {
            $html = $this->render($step, ['stepData' => $stepData]);
            $this->assertStringContainsString($needle[$step], $html, $step);
            $this->assertStringContainsString('name="_token"', $html, $step);
        }
    }

    public function testErrorsAreShownAndTheLicenseAndRecapRender(): void
    {
        $html = $this->render('license', ['licenseConfigured' => true, 'errors' => ['Phrase d\'erreur <b>']]);
        $this->assertStringContainsString('Phrase d&#039;erreur &lt;b&gt;', $html);
        $this->assertStringContainsString('name="license_key"', $html);

        $recap = $this->render('recap', ['summaries' => ['organisation' => 'Ma Boutique', 'store' => null, 'mail' => null, 'notifications' => 'web push', 'operations' => 'backup ✓ · release']]);
        $this->assertStringContainsString('Ma Boutique', $recap);
        $this->assertStringContainsString('setup_finish', $recap, 'le bouton de fin existe');
        $this->assertSame(7, substr_count($recap, '<tr>'), 'une ligne par étape sauf le récapitulatif');
    }

    public function testBundlesStepStartsWithTheQuestionnaireThenOffersGuidedCards(): void
    {
        $questions = [
            ['id' => 'timeoff', 'text' => 'Congés ?', 'hint' => 'Demandes', 'bundles' => ['timeoff'], 'icon' => '🏖', 'theme' => 'requests'],
            ['id' => 'exchange', 'text' => 'Échanges ?', 'hint' => 'Swaps', 'bundles' => ['shift-swap'], 'icon' => '🔁', 'theme' => 'requests'],
        ];
        $bundles = [
            ['slug' => 'timeoff', 'name' => 'Congés', 'description' => 'Demandes d\'absence', 'requires' => []],
            ['slug' => 'shift-swap', 'name' => 'Échanges', 'description' => 'Échanges de shifts', 'requires' => ['timeoff']],
            ['slug' => 'acme', 'name' => 'Acme', 'description' => 'Tiers', 'requires' => []],
        ];
        $cards = ['timeoff' => ['icon' => '🏖', 'theme' => 'requests', 'hint' => 'Demandes'], 'shift-swap' => ['icon' => '🔁', 'theme' => 'requests', 'hint' => 'Swaps']];
        $base = ['bundles' => $bundles, 'maxBundles' => 4, 'room' => 4, 'questions' => $questions, 'cards' => $cards, 'pending' => []];

        $ask = $this->render('bundles', $base + ['mode' => 'questions']);
        $this->assertStringContainsString('name="answers[]" value="timeoff"', $ask);
        $this->assertStringContainsString('name="answers[]" value="exchange"', $ask);
        $this->assertStringNotContainsString('name="bundles[]"', $ask, 'le questionnaire d\'abord');
        $this->assertStringContainsString('mode=list', $ask, 'la liste complète reste accessible');

        $select = $this->render('bundles', $base + ['mode' => 'select', 'recommended' => ['shift-swap', 'timeoff']]);
        $this->assertSame(3, substr_count($select, 'name="bundles[]"'), 'tout le catalogue, tiers compris');
        $this->assertSame(2, substr_count($select, 'checked'), 'les bundles recommandés sont cochés');
        $this->assertStringContainsString('data-requires="timeoff"', $select, 'prérequis exposés à la sélection');
        $this->assertStringContainsString('data-room="4"', $select);
        $this->assertStringContainsString('id="setup-bundles-search"', $select);
        $this->assertLessThan(strpos($select, 'value="shift-swap"'), strpos($select, 'value="timeoff"'), 'ordre de priorité des questions');
        $this->assertLessThan(strpos($select, 'value="acme"'), strpos($select, 'value="shift-swap"'), 'hors questionnaire en dernier');
        $this->assertStringContainsString('setup_theme_requests', $select);
        $this->assertStringContainsString('setup_theme_other', $select);
    }

    public function testPendingBundlesAreShownOnTheSelectionAndTheResult(): void
    {
        $base = ['bundles' => [['slug' => 'timeoff', 'name' => 'Congés', 'description' => '', 'requires' => []]], 'maxBundles' => 4, 'room' => 0, 'questions' => [], 'cards' => [], 'mode' => 'select'];

        $this->assertStringContainsString('setup_pending_waiting', $this->render('bundles', $base + ['pending' => ['messaging']]));
        $result = $this->render('bundles_result', ['result' => ['installed' => [], 'inactive' => [], 'failed' => [], 'pending' => ['messaging']]]);
        $this->assertStringContainsString('setup_pending_waiting', $result);
        $this->assertStringContainsString('step=license', $result);
    }

    public function testTheBundlesResultOffersToContinue(): void
    {
        $html = $this->render('bundles_result', ['result' => ['installed' => ['timeoff'], 'inactive' => [], 'failed' => ['messaging' => 'réseau']]]);

        // Les textes ne sont pas traduits ici (clés affichées) ; la liste des échecs, elle, vient du bilan.
        $this->assertStringContainsString('setup_done_installed', $html);
        $this->assertStringContainsString('<strong>messaging</strong>', $html);
        $this->assertStringContainsString('step=license', $html, 'la suite est la licence');
    }
}
