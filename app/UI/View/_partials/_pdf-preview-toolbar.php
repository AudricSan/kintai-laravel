<?php

/**
 * Barre d'outils écran pour l'aperçu d'un PDF de rapport avant génération
 * réelle — reprend le fonctionnement de l'ancienne fiche de paie autonome
 * (aperçu HTML d'abord, impression navigateur ou téléchargement PDF
 * explicite ensuite) au lieu d'un téléchargement forcé au premier clic.
 * N'affiche rien si $downloadUrl n'est pas fourni (rendu mPDF réel, qui
 * n'inclut jamais cette barre — voir HasStaffReportCrud::reportPdfDownload()).
 *
 * @var string|null $downloadUrl
 */

if (!empty($downloadUrl)):
?>
<div class="pdf-preview-toolbar">
    <button type="button" data-on-click="@print">🖨 <?= __('print_payslip') ?></button>
    <a href="<?= htmlspecialchars($downloadUrl) ?>" class="pdf-preview-toolbar__download">⬇ <?= __('download_pdf') ?></a>
    <a href="#" data-on-click="@close">✕ <?= __('close') ?></a>
    <span class="pdf-preview-toolbar__hint"><?= __('print_hint') ?></span>
</div>
<script src="<?= $BASE_URL ?>/assets/js/modules/csp-actions.js"></script>
<?php endif; ?>
