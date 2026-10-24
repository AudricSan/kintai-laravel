<?php
/**
 * Bouton « Ajouter un widget » du panneau « Personnaliser » : liste les widgets proposés mais masqués. Un clic
 * l'ajoute en fin de tableau de bord sans toucher aux autres (dashboard-layout.js, POST {add: clé}) ; un widget retiré
 * depuis sa barre d'outils revient dans cette liste.
 *
 * @var array<string, string> $_dashHidden Widgets masqués (clé => libellé)
 */
?>
<details class="dash-add-widget" data-dash-add-menu>
    <summary class="btn btn--primary btn--sm">+ <?= __('dashboard_widget_add') ?></summary>
    <div class="dash-add-widget__menu" role="menu">
        <?php foreach ($_dashHidden as $_hk => $_hl): ?>
            <button type="button" class="dash-add-widget__item" role="menuitem" data-dash-add="<?= htmlspecialchars($_hk) ?>"><?= htmlspecialchars($_hl) ?></button>
        <?php endforeach; ?>
        <p class="dash-add-widget__empty text-sm text-muted" data-dash-add-empty<?= $_dashHidden !== [] ? ' hidden' : '' ?>><?= __('dashboard_widget_all_shown') ?></p>
    </div>
</details>
<button type="button" class="btn btn--ghost btn--sm" data-dash-density aria-pressed="false" title="<?= htmlspecialchars(__('dashboard_density_hint')) ?>"><?= __('dashboard_density') ?></button>
<?php unset($_hk, $_hl); ?>
