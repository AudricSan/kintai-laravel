<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Exceptions\NotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\SetupWizard;
use kintai\Core\Setup\MailStep;
use kintai\Core\Setup\SetupQuestionnaire;
use kintai\Core\Setup\SetupSteps;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

/**
 * Assistant de configuration (/admin/setup), ouvert par l'installateur : Organisation, Premier magasin, E-mail,
 * Notifications, Exploitation, Bundles, Licence puis Récapitulatif. Chaque étape peut être faite, passée ou revisitée
 * (?step=…). Réservé à l'Owner (OwnerOnlyMiddleware, voir routes/kintai.php).
 */
final class SetupWizardController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly SetupWizard $wizard,
        private readonly LicenseClientService $license,
        private readonly AppSettingsService $settings,
        private readonly AuditLogger $auditLogger,
        private readonly SetupSteps $steps,
        private readonly SetupQuestionnaire $questionnaire,
    ) {}

    /** GET /admin/setup[?step=…] — étape demandée, sinon la première à faire ; tableau de bord si l'assistant est terminé. */
    public function show(Request $request): Response
    {
        if (!$this->wizard->isPending()) {
            return redirect()->away($this->base() . '/');
        }

        $requested = (string) $request->query('step', '');
        $step = SetupWizard::isStep($requested) ? $requested : $this->wizard->step();
        return $this->render($request, $step, ['error' => $request->query('error')]);
    }

    /**
     * POST /admin/setup/step/{step} — enregistre une étape de formulaire (organisation, magasin, e-mail, notifications,
     * exploitation) puis passe à la suivante. « skip » la passe sans rien enregistrer ; « action=test » (e-mail) essaie la
     * configuration saisie sans l'enregistrer.
     */
    public function step(Request $request): Response
    {
        $id = (string) $request->route('step');
        $step = $this->steps->get($id);
        if ($step === null) {
            throw new NotFoundException(__('error_404_message'));
        }

        if ($request->post('skip', '') === '1') {
            $this->wizard->mark($id, true);
            return redirect()->away($this->base() . '/admin/setup?step=' . SetupWizard::nextStep($id));
        }

        if ($step instanceof MailStep && $request->post('action', '') === 'test') {
            return $this->render($request, $id, ['mail_test' => $step->sendTest($request)]);
        }

        $errors = $step->handle($request, (int) ($request->attributes->get('auth_user')['id'] ?? 0));
        if ($errors !== []) {
            return $this->render($request, $id, ['errors' => array_map(static fn(string $e): string => __($e), $errors)]);
        }

        $this->wizard->mark($id);
        $this->auditLogger->log($request, 'setup.step_saved', 'system', null, ['step' => $id]);
        return redirect()->away($this->base() . '/admin/setup?step=' . SetupWizard::nextStep($id));
    }

    /** POST /admin/setup/finish — termine l'assistant (plus de bandeau) et ouvre le tableau de bord. */
    public function finish(Request $request): Response
    {
        $this->wizard->finish();
        $this->auditLogger->log($request, 'setup.finished', 'system', null, ['progress' => $this->wizard->progress()]);
        return redirect()->away($this->base() . '/');
    }

    /** POST /admin/setup/license — active la licence saisie, ou passe l'étape (plan gratuit). */
    public function license(Request $request): Response
    {
        if ($request->post('skip', '') === '1') {
            $this->wizard->mark(SetupWizard::STEP_LICENSE, true);
            return redirect()->away($this->base() . '/admin/setup?step=' . SetupWizard::STEP_RECAP);
        }

        $key = trim((string) $request->post('license_key', ''));
        if ($key === '') {
            return redirect()->away($this->base() . '/admin/setup?step=license&error=missing_license_key');
        }
        $result = $this->license->activate($key);
        $this->auditLogger->log($request, 'license.activated', 'system', null, ['valid' => (bool) ($result['valid'] ?? false), 'from' => 'setup']);
        if (!($result['valid'] ?? false)) {
            return redirect()->away($this->base() . '/admin/setup?step=license&error=license_invalid');
        }

        $this->wizard->licenseDone();
        $pending = $this->wizard->pendingBundles() === [] ? null : $this->wizard->installPending($this->settings->bundleUpdateChannel());
        if ($pending !== null) {
            $this->auditLogger->log($request, 'setup.pending_bundles_installed', 'system', null, ['installed' => $pending['installed'], 'failed' => array_keys($pending['failed'])]);
            return $this->render($request, 'bundles_result', ['result' => $pending, 'next' => SetupWizard::STEP_RECAP]);
        }
        return redirect()->away($this->base() . '/admin/setup?step=' . SetupWizard::STEP_RECAP);
    }

    /**
     * POST /admin/setup/questions — réponses du questionnaire : affiche la sélection des bundles avec ceux que les
     * réponses recommandent déjà cochés (modifiable avant de valider).
     */
    public function questions(Request $request): Response
    {
        $catalog   = $this->wizard->availableBundles($this->settings->bundleUpdateChannel());
        $questions = $this->questionnaire->questions($catalog, user_locale(null));
        $yes       = array_values(array_map('strval', (array) $request->post('answers', [])));

        return $this->render($request, SetupWizard::STEP_BUNDLES, [
            'mode'        => 'select',
            'recommended' => $this->questionnaire->recommend($questions, $yes),
        ]);
    }

    /**
     * POST /admin/setup/bundles — installe et active les bundles choisis dans l'ordre de priorité du questionnaire, dans la
     * limite du quota : les autres sont mémorisés « en attente de licence » et s'installeront d'eux-mêmes quand une licence
     * le permettra. Affiche le bilan. Avec « skip », passe l'étape sans rien installer.
     */
    public function bundles(Request $request): Response
    {
        if ($request->post('skip', '') === '1') {
            $this->wizard->mark(SetupWizard::STEP_BUNDLES, true);
            return redirect()->away($this->base() . '/admin/setup?step=' . SetupWizard::STEP_LICENSE);
        }

        $slugs = array_values(array_filter(array_map('strval', (array) $request->post('bundles', [])), static fn(string $s): bool => $s !== ''));
        $catalog = $this->wizard->availableBundles($this->settings->bundleUpdateChannel());
        $slugs = $this->questionnaire->prioritise($slugs, $this->questionnaire->questions($catalog, $this->locale()));
        set_time_limit(0);
        $result = $this->wizard->install($slugs, $this->settings->bundleUpdateChannel());
        if ($result['error'] !== null) {
            return redirect()->away($this->base() . '/admin/setup?step=bundles&error=' . $result['error']);
        }
        $this->auditLogger->log($request, 'setup.bundles_installed', 'system', null, [
            'installed' => $result['installed'],
            'failed'    => array_keys($result['failed']),
        ]);
        $this->wizard->mark(SetupWizard::STEP_BUNDLES);

        return $this->render($request, 'bundles_result', ['result' => $result]);
    }

    private function locale(): string
    {
        return user_locale(null);
    }

    /** @param array<string, mixed> $extra variables propres à l'écran (erreurs, essai d'e-mail, bilan des bundles) */
    private function render(Request $request, string $step, array $extra = []): Response
    {
        $progress = $this->wizard->progress();
        $screen = $step === 'bundles_result' ? SetupWizard::STEP_BUNDLES : $step;
        $data = array_replace([
            'title'    => __('setup_title'),
            'step'     => $step,
            'steps'    => SetupWizard::STEPS,
            'progress' => $progress,
            'position' => SetupWizard::position($screen),
            'total'    => count(SetupWizard::STEPS),
            'previous' => SetupWizard::previousStep($screen),
            'next'     => SetupWizard::nextStep($screen),
            'skippable' => in_array($screen, SetupWizard::SKIPPABLE, true),
            'error'    => null,
            'errors'   => [],
        ], $extra);

        $configStep = $this->steps->get($step);
        if ($configStep !== null) {
            $data['stepData'] = $configStep->data($request);
        } elseif ($step === SetupWizard::STEP_LICENSE) {
            $data['licenseConfigured'] = $this->license->isConfigured();
            $data['licenseError'] = $this->license->state()['error'] ?? null;
        } elseif ($step === SetupWizard::STEP_BUNDLES) {
            $catalog = $this->wizard->availableBundles($this->settings->bundleUpdateChannel());
            $questions = $this->questionnaire->questions($catalog, $this->locale());
            $data['bundles'] = $catalog;
            $data['maxBundles'] = $this->wizard->maxBundles();
            $data['room'] = $this->wizard->room();
            $data['questions'] = $questions;
            $data['cards'] = $this->questionnaire->cards($questions);
            $data['pending'] = $this->wizard->pendingBundles();
            // Questionnaire d'abord ; la liste complète à la demande (?mode=list) ou après les réponses.
            $data['mode'] = $extra['mode'] ?? ($request->query('mode') === 'list' ? 'select' : 'questions');
            $data['recommended'] = $extra['recommended'] ?? [];
        } elseif ($step === SetupWizard::STEP_RECAP) {
            $summaries = [];
            foreach ($this->steps->all() as $id => $s) {
                $summaries[$id] = $s->summary();
            }
            $data['summaries'] = $summaries;
        }

        return response($this->view->render('system.setup', $data, 'layout.app'));
    }
}
