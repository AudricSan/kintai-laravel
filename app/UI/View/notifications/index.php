<?php
use kintai\UI\Components\Pagination;

/** @var array[] $notifications */
/** @var int     $page */
/** @var int     $totalPages */
?>

<div class="page-header">
    <h2 class="page-header__title"><?= __('notifications') ?></h2>
    <?php if (!empty($notifications)): ?>
    <div class="page-header__actions">
        <form method="POST" action="<?= route_url('notifications.read_all') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--ghost btn--sm"><?= __('mark_all_read') ?></button>
        </form>
        <form method="POST" action="<?= route_url('notifications.delete_all') ?>" data-confirm="<?= htmlspecialchars(__('delete_all_notifications_confirm'), ENT_QUOTES) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--ghost btn--sm"><?= __('delete_all_notifications') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
    <div class="empty-state empty-state--bell">
        <p><?= __('no_notifications') ?></p>
    </div>
<?php else: ?>
    <div class="notif-list">
        <?php foreach ($notifications as $n): ?>
            <?php $isRead = (int) ($n['is_read'] ?? 0); ?>
            <div class="notif-item<?= $isRead ? '' : ' notif-item--unread' ?>">
                <a href="<?= route_url('notifications.open', ['id' => (int) $n['id']]) ?>" class="notif-item__link">
                    <span class="notif-item__icon"><?= notification_type_icon($n['type'] ?? '') ?></span>
                    <div class="notif-item__content">
                        <div class="notif-item__title"><?= htmlspecialchars(notification_type_label($n['type'] ?? '')) ?></div>
                        <div class="notif-item__body"><?= htmlspecialchars(notification_body($n)) ?></div>
                        <div class="notif-item__time"><?= htmlspecialchars(substr($n['created_at'] ?? '', 0, 16)) ?></div>
                    </div>
                </a>
                <?php if (!$isRead): ?>
                <form method="POST" action="<?= $BASE_URL ?>/notifications/<?= (int) $n['id'] ?>/read" class="notif-item__action">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--ghost btn--xs" title="<?= __('mark_as_read') ?>">✓</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (($totalPages ?? 1) > 1): ?>
        <?= Pagination::make((int) $page, (int) $totalPages)->render() ?>
    <?php endif; ?>
<?php endif; ?>
