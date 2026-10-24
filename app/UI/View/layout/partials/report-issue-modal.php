<?php
/* Modale "Signaler un problème" — déclenchée depuis le footer applicatif
 * (voir partials/_footer.php, bouton data-on-click="openModal"). Crée directement
 * une issue sur le dépôt GitHub du projet (voir SupportController).
 * Utilise le composant .modal du Core (modals.css + openModal/closeModal d'app.js) :
 * elle reste masquée tant qu'elle n'est pas ouverte, quels que soient les bundles installés.
 * Variables disponibles via ViewRenderer::share() : $BASE_URL
 */

// Valeurs lues dans l'URL : uniquement des chaînes (?ri_success[]=x faisait planter htmlspecialchars() sur
// chaque page), et le lien « voir l'incident » n'est affiché que s'il pointe vraiment vers une issue GitHub
// (sinon une URL forgée y plaçait n'importe quelle destination).
$riError   = is_string($_GET['ri_error'] ?? null) ? $_GET['ri_error'] : null;
$riSuccess = is_string($_GET['ri_success'] ?? null)
    && preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/\d+$#', $_GET['ri_success']) === 1
    ? $_GET['ri_success']
    : null;
// Rouverte d'office après un envoi (succès ou erreur) pour afficher le résultat.
$riOpen    = $riError !== null || $riSuccess !== null;
?>

<div id="report-issue-modal" class="modal<?= $riOpen ? ' open' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="ri-modal-title">
    <div class="modal__backdrop" data-on-click="closeModal" data-args='["report-issue-modal"]'></div>
    <div class="modal__dialog">

        <div class="modal__header">
            <h3 class="modal__title" id="ri-modal-title"><?= __('report_issue_modal_title') ?></h3>
            <button type="button" class="modal__close" data-on-click="closeModal" data-args='["report-issue-modal"]' aria-label="<?= __('close') ?>">&times;</button>
        </div>

        <div class="modal__body">

            <?php if ($riSuccess !== null): ?>
                <div class="alert alert--success mb-sm">
                    <?= __('report_issue_success') ?>
                    <a href="<?= htmlspecialchars($riSuccess, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= __('report_issue_view_issue') ?></a>
                </div>
            <?php elseif ($riError !== null): ?>
                <div class="alert alert--error mb-sm">
                    <?php if ($riError === 'empty'): ?>
                        <?= __('report_issue_error_empty') ?>
                    <?php else: ?>
                        <?= __('report_issue_error_generic') ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="form-hint mb-sm"><?= __('report_issue_privacy_note') ?></p>

            <form method="POST" action="<?= route_url('support.report_issue') ?>" id="ri-form" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/') ?>">

                <div class="form-group">
                    <label class="form-label form-label--required" for="ri-title">
                        <?= __('report_issue_title_label') ?>
                    </label>
                    <input type="text" name="title" id="ri-title" class="form-control"
                           maxlength="200" required
                           placeholder="<?= __('report_issue_title_placeholder') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label form-label--required" for="ri-description">
                        <?= __('report_issue_description_label') ?>
                    </label>
                    <textarea name="description" id="ri-description" class="form-control"
                              rows="5" required maxlength="4000"
                              placeholder="<?= __('report_issue_description_placeholder') ?>"></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><?= __('report_issue_submit') ?></button>
                    <button type="button" class="btn btn--ghost" data-on-click="closeModal" data-args='["report-issue-modal"]'><?= __('cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
