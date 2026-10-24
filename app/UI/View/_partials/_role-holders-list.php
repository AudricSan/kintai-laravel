<?php
/**
 * Liste des utilisateurs ayant un rôle, sous forme de bulles (puce
 * d'initiales colorée + nom + store) — inclus par roles-form.php.
 * @var array $holders   ['assignment_id','user_name','initials','color','scope_label'][]
 * @var bool  $removable Affiche un bouton de retrait par bulle (rôles non-système en édition)
 */
$removable ??= false;
?>
<?php if (empty($holders)): ?>
    <p class="form-hint"><?= __('none') ?></p>
<?php else: ?>
    <ul class="role-holders-list">
        <?php foreach ($holders as $h): ?>
            <li class="role-holder-chip">
                <span class="avatar-chip <?= style_class(['--chip-bg' => $h['color']]) ?>"><?= htmlspecialchars($h['initials']) ?></span>
                <span class="role-holder-chip__body">
                    <span class="role-holder-chip__name"><?= htmlspecialchars($h['user_name']) ?></span>
                    <span class="role-holder-chip__scope"><?= htmlspecialchars($h['scope_label']) ?></span>
                </span>
                <?php if ($removable): ?>
                <!-- Pas de <form> imbriqué : ce partiel est inclus à l'intérieur du grand
                     formulaire d'édition du rôle. Le bouton cible le formulaire externe et
                     vide #roleHolderRemoveForm (voir roles-form.php) via form=/formaction,
                     même technique que le bouton de réinitialisation de mot de passe de
                     _form-user.php. La modale globale data-confirm cherche son formulaire
                     via closest() dans le DOM, inopérant ici : confirm() natif à la place. -->
                <button type="submit" form="roleHolderRemoveForm"
                        formaction="<?= route_url('admin.roles.holders.delete', ['id' => (int) $role['id'], 'assignmentId' => $h['assignment_id']]) ?>"
                        class="role-holder-chip__remove btn btn--danger btn--sm" title="<?= htmlspecialchars(__('remove')) ?>"
                        data-confirm="<?= htmlspecialchars(__('confirm_remove_role_holder'), ENT_QUOTES) ?>">×</button>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
