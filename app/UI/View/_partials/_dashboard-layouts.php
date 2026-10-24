<?php
/**
 * Mises en page nommées et défaut par rôle, dans le panneau « Personnaliser » (formulaires POST, sans JavaScript).
 * Contrôleur : DashboardLayoutsController.
 *
 * @var list<array{id: int, name: string, is_active: bool}>      $dashboard_layouts      Mises en page de l'utilisateur
 * @var string                                                    $dashboard_layouts_route Nom de la route cible des formulaires
 * @var array<int, array{name: string, has_default: bool}>        $dashboard_roles        Rôles (Owner seulement)
 */
$_layouts = $dashboard_layouts ?? [];
$_roles   = $dashboard_roles ?? [];
if (empty($dashboard_layouts_route)) {
    return;
}
$_url     = route_url($dashboard_layouts_route);
$_max     = \kintai\Core\Repositories\UserDashboardLayoutRepositoryInterface::MAX_PER_DASHBOARD;
$_active  = null;
foreach ($_layouts as $_l) {
    if ($_l['is_active']) {
        $_active = $_l;
    }
}
?>
<div class="dash-layouts">
    <h4 class="dash-layouts__title"><?= __('dashboard_layouts_title') ?></h4>

    <?php if ($_layouts !== []): ?>
        <form method="POST" action="<?= htmlspecialchars($_url) ?>" class="dash-layouts__row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="activate">
            <label class="dash-layouts__label" for="dash-layout-select"><?= __('dashboard_layouts_current') ?></label>
            <select name="id" id="dash-layout-select" class="form-control" data-submit-on-change>
                <?php if ($_active === null): ?>
                    <option value="" selected><?= __('dashboard_layouts_unsaved') ?></option>
                <?php endif; ?>
                <?php foreach ($_layouts as $_l): ?>
                    <option value="<?= (int) $_l['id'] ?>"<?= $_l['is_active'] ? ' selected' : '' ?>><?= htmlspecialchars($_l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($_active !== null): ?>
            <div class="dash-layouts__row">
                <form method="POST" action="<?= htmlspecialchars($_url) ?>" class="dash-layouts__inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="id" value="<?= (int) $_active['id'] ?>">
                    <input type="text" name="name" class="form-control" maxlength="60" required value="<?= htmlspecialchars($_active['name']) ?>"
                           aria-label="<?= htmlspecialchars(__('dashboard_layouts_rename')) ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"><?= __('dashboard_layouts_rename') ?></button>
                </form>
                <form method="POST" action="<?= htmlspecialchars($_url) ?>" data-confirm="<?= htmlspecialchars(__('dashboard_layouts_delete_confirm'), ENT_QUOTES) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $_active['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm dash-layouts__delete"><?= __('delete') ?></button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (count($_layouts) < $_max): ?>
        <form method="POST" action="<?= htmlspecialchars($_url) ?>" class="dash-layouts__row dash-layouts__inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="text" name="name" class="form-control" maxlength="60" required
                   placeholder="<?= htmlspecialchars(__('dashboard_layouts_name_placeholder')) ?>"
                   aria-label="<?= htmlspecialchars(__('dashboard_layouts_name_placeholder')) ?>">
            <button type="submit" class="btn btn--primary btn--sm"><?= __('dashboard_layouts_save_as') ?></button>
        </form>
    <?php else: ?>
        <p class="text-sm text-muted"><?= __('dashboard_layouts_limit') ?></p>
    <?php endif; ?>

    <?php if ($_roles !== []): ?>
        <div class="dash-layouts__role">
            <h4 class="dash-layouts__title"><?= __('dashboard_layouts_role_title') ?></h4>
            <p class="text-sm text-muted"><?= __('dashboard_layouts_role_hint') ?></p>
            <form method="POST" action="<?= htmlspecialchars($_url) ?>" class="dash-layouts__row dash-layouts__inline">
                <?= csrf_field() ?>
                <select name="role_id" class="form-control" aria-label="<?= htmlspecialchars(__('dashboard_layouts_role_title')) ?>">
                    <?php foreach ($_roles as $_rid => $_role): ?>
                        <option value="<?= (int) $_rid ?>"><?= htmlspecialchars($_role['name']) ?><?= $_role['has_default'] ? ' ✓' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="action" value="role_default" class="btn btn--ghost btn--sm"><?= __('dashboard_layouts_role_set') ?></button>
                <button type="submit" name="action" value="role_clear" class="btn btn--ghost btn--sm"><?= __('dashboard_layouts_role_clear') ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php unset($_layouts, $_url, $_roles, $_max, $_active, $_l, $_rid, $_role); ?>
