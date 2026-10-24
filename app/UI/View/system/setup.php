<?php
/**
 * Assistant de configuration (SetupWizardController) : une étape à la fois, avec la barre de progression.
 *
 * @var string                    $step       étape affichée (organisation… recap, ou bundles_result)
 * @var list<string>              $steps      toutes les étapes, dans l'ordre
 * @var array<string, ?string>    $progress   état de chaque étape : done, skipped ou null
 * @var int                       $position   rang de l'étape (à partir de 1)
 * @var int                       $total
 * @var string|null               $previous   étape précédente
 * @var string                    $next       étape suivante
 * @var bool                      $skippable  l'étape peut être passée
 * @var string|null               $error      erreur (clé) passée dans l'URL
 * @var list<string>              $errors     erreurs de saisie (phrases)
 * @var array<string, mixed>|null $stepData   variables de l'étape de formulaire
 * @var bool|null                 $licenseConfigured
 * @var string|null               $licenseError
 * @var list<array<string, mixed>>|null $bundles
 * @var int|null                  $maxBundles
 * @var array<string, mixed>|null $result     bilan de l'installation des bundles
 * @var array<string, ?string>|null $summaries  récapitulatif des étapes de formulaire
 * @var array<string, mixed>|null $mail_test  résultat de l'essai d'e-mail
 */
$error ??= null;
$errors ??= [];
$stepData ??= [];
$errorMessages = [
    'missing_license_key'    => __('license_missing_key'),
    'license_invalid'        => __('setup_license_invalid'),
    'setup_bundles_unknown'  => __('setup_bundles_unknown'),
];
$screen = $step === 'bundles_result' ? 'bundles' : $step;
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('setup_title') ?></h2>
</div>

<ol class="setup-steps" aria-label="<?= htmlspecialchars(__('setup_progress')) ?>">
    <?php foreach ($steps as $_id): ?>
        <?php
        $_state = $progress[$_id] ?? null;
        $_class = 'setup-steps__item'
            . ($_id === $screen ? ' setup-steps__item--current' : '')
            . ($_state === 'done' ? ' setup-steps__item--done' : '')
            . ($_state === 'skipped' ? ' setup-steps__item--skipped' : '');
        ?>
        <li class="<?= $_class ?>"<?= $_id === $screen ? ' aria-current="step"' : '' ?>>
            <a href="<?= route_url('admin.setup') ?>?step=<?= htmlspecialchars($_id) ?>">
                <span class="setup-steps__mark" aria-hidden="true"><?= $_state === 'done' ? '✓' : ($_state === 'skipped' ? '–' : (string) (array_search($_id, $steps, true) + 1)) ?></span>
                <span class="setup-steps__label"><?= __('setup_step_' . $_id) ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ol>
<p class="text-muted mb-sm"><?= __('setup_step_of', ['n' => $position, 'total' => $total]) ?></p>

<?php if ($error !== null && isset($errorMessages[$error])): ?>
    <div class="alert alert--danger mb-sm"><?= htmlspecialchars($errorMessages[$error]) ?>
        <?php if ($error === 'license_invalid' && !empty($licenseError)): ?> (<?= htmlspecialchars((string) $licenseError) ?>)<?php endif; ?></div>
<?php endif; ?>
<?php foreach ($errors as $_message): ?>
    <div class="alert alert--danger mb-sm"><?= htmlspecialchars((string) $_message) ?></div>
<?php endforeach; ?>

<?php
$partial = __DIR__ . '/setup/_' . $step . '.php';
if (is_file($partial)) {
    include $partial;
}
?>

<?php if ($previous !== null && $step !== 'bundles_result'): ?>
    <p class="mt-sm"><a href="<?= route_url('admin.setup') ?>?step=<?= htmlspecialchars($previous) ?>">← <?= __('setup_back') ?></a></p>
<?php endif; ?>
