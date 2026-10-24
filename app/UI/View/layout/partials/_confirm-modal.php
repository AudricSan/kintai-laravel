<?php
/* Modale de confirmation générique — incluse une fois dans layout/app.php.
 * Tout formulaire de suppression pose data-confirm="message" au lieu de
 * onsubmit="return confirm(...)" ; confirm-modal.js intercepte le submit.
 */

use kintai\UI\Components\Modal;
?>

<?= Modal::make('global-confirm-modal')
    ->title(__('confirm'))
    ->body('<p id="global-confirm-message"></p>')
    ->footer(
        '<button type="button" class="btn btn--ghost" data-on-click="closeModal" data-args=\'["global-confirm-modal"]\'>' . htmlspecialchars(__('cancel')) . '</button>'
        . '<button type="button" id="global-confirm-submit-btn" class="btn btn--danger">' . htmlspecialchars(__('confirm')) . '</button>'
    )
    ->render() ?>
<script src="<?= $BASE_URL ?>/assets/js/modules/confirm-modal.js"></script>
