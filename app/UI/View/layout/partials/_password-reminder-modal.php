<?php
/* Fenêtre de rappel du mot de passe — affichée ouverte une fois par connexion tant que le mot de passe est le
 * mot de passe par défaut « 0000 » ou trop court (PasswordReminderMiddleware). Jamais bloquante : « Plus tard »
 * la ferme, le bandeau en haut de page reste comme rappel.
 */

use kintai\UI\Components\Modal;

$_pwModalId = 'password-reminder-modal';
?>
<?= Modal::make($_pwModalId)
    ->attrs(['class' => 'open', 'role' => 'dialog', 'aria-modal' => 'true'])
    ->title('🔑 ' . htmlspecialchars(__('password_reminder_title')))
    ->body('<p>' . htmlspecialchars(__('password_reminder_body')) . '</p>')
    ->footer(
        '<button type="button" class="btn btn--ghost" data-on-click="closeModal" data-args=\'["' . $_pwModalId . '"]\'>'
        . htmlspecialchars(__('password_reminder_later')) . '</button>'
        . '<a href="' . htmlspecialchars(route_url('profile') . '?tab=info') . '" class="btn btn--primary">'
        . htmlspecialchars(__('password_reminder_action')) . '</a>'
    )
    ->render() ?>
