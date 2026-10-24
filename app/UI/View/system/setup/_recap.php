<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/**
 * @var array<string, ?string> $summaries résumé des étapes de formulaire (null = rien de configuré)
 * @var array<string, ?string> $progress
 */
$links = [
    'organisation' => route_url('admin.owner_settings'),
    'store'        => route_url('admin.stores'),
    'mail'         => route_url('admin.mail_test'),
    'notifications' => route_url('admin.notifications_settings'),
    'operations'   => route_url('admin.backup'),
    'bundles'      => route_url('admin.bundles.market'),
    'license'      => route_url('admin.license'),
];
ob_start();
?>
    <p class="mb-sm"><?= __('setup_recap_intro') ?></p>
    <table class="data-table setup-recap">
        <tbody>
        <?php foreach ($links as $_id => $_link): ?>
            <?php $_state = $progress[$_id] ?? null; ?>
            <tr>
                <th scope="row"><a href="<?= route_url('admin.setup') ?>?step=<?= htmlspecialchars($_id) ?>"><?= __('setup_step_' . $_id) ?></a></th>
                <td>
                    <?php if ($_state === 'done'): ?>
                        <span class="badge badge--success"><?= __('setup_state_done') ?></span>
                    <?php elseif ($_state === 'skipped'): ?>
                        <span class="badge badge--inactive"><?= __('setup_state_skipped') ?></span>
                    <?php else: ?>
                        <span class="badge badge--warning"><?= __('setup_state_todo') ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-muted text-sm"><?= htmlspecialchars((string) ($summaries[$_id] ?? '')) ?></td>
                <td class="text-right"><a href="<?= htmlspecialchars($_link) ?>"><?= __('setup_recap_settings') ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="POST" action="<?= route_url('admin.setup.finish') ?>" class="mt-sm">
        <?= csrf_field() ?>
        <div class="form-actions">
            <?= Button::make(__('setup_finish'))->primary()->submit()->render() ?>
        </div>
        <p class="form-hint"><?= __('setup_finish_hint') ?></p>
    </form>
<?php echo Card::make()->header(__('setup_step_recap'))->body(ob_get_clean())->render(); ?>
