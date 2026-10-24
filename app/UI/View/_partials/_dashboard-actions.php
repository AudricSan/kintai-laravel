<?php
/**
 * Actions de l'en-tête d'un tableau de bord : « Actualiser » (force le rafraîchissement des widgets qui en ont un),
 * « Activer le mode mural » (plein écran sans barres, pour un écran de magasin) et l'indication de la dernière mise à
 * jour. Comportement dans dashboard-live.js. Le bouton « Quitter » du mode mural n'est visible que dans ce mode.
 */
?>
<span class="dash-updated text-xs text-muted" data-dash-updated aria-live="polite"
      data-label-now="<?= htmlspecialchars(__('dashboard_updated_now')) ?>"
      data-label-ago="<?= htmlspecialchars(__('dashboard_updated_ago')) ?>"
      data-label-expired="<?= htmlspecialchars(__('dashboard_session_expired')) ?>"></span>
<button type="button" class="btn btn--ghost btn--sm" data-dash-refresh-now><?= __('dashboard_refresh_now') ?></button>
<button type="button" class="btn btn--ghost btn--sm" data-dash-wall aria-pressed="false"><?= __('dashboard_wall_enter') ?></button>
<button type="button" class="btn btn--primary btn--sm dash-wall-exit" data-dash-wall-exit hidden disabled><?= __('dashboard_wall_exit') ?></button>
<?php
/*
 * Boîte du mot de passe du mode mural. À l'activation on en choisit un (saisi deux fois) ; pour quitter, on le redonne.
 * Il ne quitte jamais la page : gardé en mémoire par dashboard-live.js, perdu au rechargement (qui quitte aussi le mode).
 * Une seule boîte sert aux deux cas : le JS en règle les champs et les textes (data-set-* / data-unlock-*).
 */
?>
<dialog class="wall-lock" data-wall-lock>
    <form method="dialog" class="wall-lock__form" data-wall-lock-form autocomplete="off">
        <h3 class="wall-lock__title" data-wall-lock-title></h3>
        <p class="wall-lock__hint text-sm text-muted" data-wall-lock-hint></p>
        <label class="wall-lock__field">
            <span class="wall-lock__label"><?= __('dashboard_wall_password') ?></span>
            <input type="password" class="form-control" data-wall-lock-password autocomplete="new-password" required>
        </label>
        <label class="wall-lock__field" data-wall-lock-confirm-row>
            <span class="wall-lock__label"><?= __('dashboard_wall_password_confirm') ?></span>
            <input type="password" class="form-control" data-wall-lock-confirm autocomplete="new-password">
        </label>
        <p class="wall-lock__error text-sm" data-wall-lock-error role="alert" hidden></p>
        <div class="wall-lock__actions">
            <button type="button" class="btn btn--ghost btn--sm" data-wall-lock-cancel><?= __('cancel') ?></button>
            <button type="submit" class="btn btn--primary btn--sm" data-wall-lock-submit></button>
        </div>
    </form>
    <span hidden data-wall-lock-texts
          data-set-title="<?= htmlspecialchars(__('dashboard_wall_set_title')) ?>"
          data-set-hint="<?= htmlspecialchars(__('dashboard_wall_set_hint')) ?>"
          data-set-submit="<?= htmlspecialchars(__('dashboard_wall_set_submit')) ?>"
          data-unlock-title="<?= htmlspecialchars(__('dashboard_wall_unlock_title')) ?>"
          data-unlock-hint="<?= htmlspecialchars(__('dashboard_wall_unlock_hint')) ?>"
          data-unlock-submit="<?= htmlspecialchars(__('dashboard_wall_exit')) ?>"
          data-err-short="<?= htmlspecialchars(__('dashboard_wall_err_short')) ?>"
          data-err-mismatch="<?= htmlspecialchars(__('dashboard_wall_err_mismatch')) ?>"
          data-err-wrong="<?= htmlspecialchars(__('dashboard_wall_err_wrong')) ?>"></span>
</dialog>