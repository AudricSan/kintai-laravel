<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\EmptyState;
use kintai\UI\Components\Modal;
use kintai\UI\Components\Pagination;
use kintai\UI\Components\Tabs;
use kintai\UI\Components\Table;

/** @var string $tab */
if ($tab === 'error-file'):
    /** @var array  $err_content */
    /** @var int    $err_lines */
    /** @var string $err_path */
    /** @var int    $err_size */
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('logs_error_file') ?></h2>
</div>

<?php
$errTab = ($tab === 'error-file') ? '?tab=error-file' : '?';
echo Tabs::make()
    ->items(['?' => __('activity_log'), '?tab=error-file' => __('logs_error_file')])
    ->active($errTab)
    ->render();
?>

<div class="mb-sm text-sm-muted">
    <span><?= __('logs_error_file_size') ?>: <?= $err_size > 0 ? sprintf('%.1f KB', $err_size / 1024) : '—' ?></span>
    &middot;
    <span><?= __('logs_error_file_showing') ?>: <?= count($err_content) ?> / <?= $err_size > 0 ? __('logs_lines') : 0 ?></span>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" action="" class="flex-row gap-sm">
            <input type="hidden" name="tab" value="error-file">
            <label class="form-label" for="lines"><?= __('logs_lines') ?></label>
            <select id="lines" name="lines" class="form-control form-control-sm" data-submit-on-change>
                <option value="50" <?= $err_lines === 50 ? 'selected' : '' ?>>50</option>
                <option value="200" <?= $err_lines === 200 ? 'selected' : '' ?>>200</option>
                <option value="1000" <?= $err_lines === 1000 ? 'selected' : '' ?>>1000</option>
                <option value="5000" <?= $err_lines === 5000 ? 'selected' : '' ?>>5000</option>
            </select>
            <?= Button::make(__('apply'))->primary()->sm()->submit()->render() ?>
            <a href="?tab=error-file" class="btn btn--ghost btn--sm"><?= __('reset') ?></a>
        </form>
    </div>

    <?php if (empty($err_content)):
        echo EmptyState::make(__('logs_error_file_empty'))->render();
    else: ?>
        <pre class="error-log__pre"><?= htmlspecialchars(implode("\n", $err_content)) ?></pre>
    <?php endif; ?>
</div>

<?php return; endif; ?>
<?php /** @var array  $rows */
/** @var int    $total */
/** @var int    $page */
/** @var int    $totalPages */
/** @var array  $levels */
/** @var array  $channels */
/** @var array  $actions */
/** @var array  $resource_types */
/** @var array  $users_map */
/** @var array  $filters */
$levelFilter    = $filters['level']         ?? '';
$channelFilter  = $filters['channel']       ?? '';
$actionFilter   = $filters['action']        ?? '';
$resourceFilter = $filters['resource_type'] ?? '';
$userIdFilter   = $filters['user_id']       ?? '';
$fromFilter     = $filters['from']          ?? '';
$toFilter       = $filters['to']            ?? '';
$queryFilter    = $filters['query']         ?? '';

$actionLabels = [
    'auth.login'                         => __('audit_auth_login'),
    'auth.login_failed'                  => __('audit_auth_login_failed'),
    'auth.logout'                        => __('audit_auth_logout'),
    'user.created'                       => __('audit_user_created'),
    'user.updated'                       => __('audit_user_updated'),
    'user.deleted'                       => __('audit_user_deleted'),
    'user.update_profile'                => __('audit_user_update_profile'),
    'user.change_password'               => __('audit_user_change_password'),
    'user.password_reset'                => __('audit_user_password_reset'),
    'user_rate.saved'                    => __('audit_user_rate_saved'),
    'user_rate.deleted'                  => __('audit_user_rate_deleted'),
    'store.created'                      => __('audit_store_created'),
    'store.updated'                      => __('audit_store_updated'),
    'store.deleted'                      => __('audit_store_deleted'),
    'store.member_added'                 => __('audit_store_member_added'),
    'store.member_role_updated'          => __('audit_store_member_role_updated'),
    'store.member_removed'               => __('audit_store_member_removed'),
    'store.member_deductions_updated'    => __('audit_store_member_deductions_updated'),
    'shift.created'                      => __('audit_shift_created'),
    'shift.updated'                      => __('audit_shift_updated'),
    'shift.deleted'                      => __('audit_shift_deleted'),
    'shift.bulk_deleted'                 => __('audit_shift_bulk_deleted'),
    'shift.moved'                        => __('audit_shift_moved'),
    'shift.conflict_resolved'            => __('audit_shift_conflict_resolved'),
    'shift.bulk_conflict_resolved'       => __('audit_shift_bulk_conflict_resolved'),
    'shifts.imported'                    => __('audit_shifts_imported'),
    'shift_type.created'                 => __('audit_shift_type_created'),
    'shift_type.updated'                 => __('audit_shift_type_updated'),
    'shift_type.deleted'                 => __('audit_shift_type_deleted'),
    'system.migration_applied'           => __('audit_system_migration_applied'),
    'backup.created'                     => __('audit_backup_created'),
    'backup.restored'                    => __('audit_backup_restored'),
    'backup.deleted'                     => __('audit_backup_deleted'),
    'backup.deleted_all'                 => __('audit_backup_deleted_all'),
    'backup.settings_updated'            => __('audit_backup_settings_updated'),
    'update.channel_changed'             => __('audit_update_channel_changed'),
    'update.applied'                     => __('audit_update_applied'),
    'db.migration_failed'                => __('audit_db_migration_failed'),
    'log.purged'                         => __('audit_log_purged'),
    'activity_log.exported'              => __('audit_activity_log_exported'),
    'activity_log.purged'                => __('audit_activity_log_manual_purged'),
    'api_token.created'                  => __('audit_api_token_created'),
    'api_token.revoked'                  => __('audit_api_token_revoked'),
    'auth.api_login_failed'              => __('audit_auth_api_login_failed'),
    'owner_settings.updated'             => __('audit_owner_settings_updated'),
    'nav_settings.updated'               => __('audit_nav_settings_updated'),
    'dashboard_prefs.updated'            => __('audit_dashboard_prefs_updated'),
    'availability.updated'               => __('audit_availability_updated'),
    'export.store_stats'                 => __('audit_export_store_stats'),
    'export.payslip_pdf'                 => __('audit_export_payslip_pdf'),
    'export.ical'                        => __('audit_export_ical'),
    'ical_token.regenerated'             => __('audit_ical_token_regenerated'),
    'store_user.created'                  => __('audit_store_user_created'),
    'store_user.deleted'                  => __('audit_store_user_deleted'),
];
?>
<?php
$exportParams = array_filter([
    'level'         => $levelFilter ?: null,
    'channel'       => $channelFilter ?: null,
    'action'        => $actionFilter ?: null,
    'resource_type' => $resourceFilter ?: null,
    'from'          => $fromFilter ?: null,
    'to'            => $toFilter ?: null,
    'user_id'       => $userIdFilter ?: null,
    'query'         => $queryFilter ?: null,
]);
$exportUrl = route_url('admin.activity.export') . ($exportParams ? '?' . http_build_query($exportParams) : '');
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('activity_log') ?> <span class="page-count">(<?= $total ?>)</span></h2>
    <div class="page-header__actions">
        <?= Button::make('⬇ ' . __('export_csv'))->ghost()->sm()->link($exportUrl)->render() ?>
    </div>
</div>

<?php if (!empty($purged) || ($purged ?? null) === 0): ?>
    <?= \kintai\UI\Components\Alert::make(__('activity_log_purged_done', ['count' => (int) $purged]))->success()->render() ?>
<?php elseif (!empty($purge_error)): ?>
    <?= \kintai\UI\Components\Alert::make(__('activity_log_purge_invalid'))->danger()->render() ?>
<?php endif; ?>

<?php if (!empty($can_purge)): ?>
<div class="card mb-sm">
    <form method="POST" action="<?= route_url('admin.activity.purge') ?>" class="flex-row gap-sm"
          data-confirm="<?= htmlspecialchars(__('activity_log_purge_confirm'), ENT_QUOTES) ?>">
        <?= csrf_field() ?>
        <label class="form-label" for="al-purge-keep"><?= __('activity_log_purge') ?></label>
        <select id="al-purge-keep" name="keep_days" class="form-control form-control-sm">
            <?php foreach ($purge_options as $_days): ?>
                <option value="<?= $_days ?>"><?= $_days === 0 ? __('activity_log_purge_all') : __('activity_log_purge_keep', ['days' => $_days]) ?></option>
            <?php endforeach; ?>
        </select>
        <?= Button::make(__('activity_log_purge_button'))->danger()->sm()->submit()->render() ?>
    </form>
    <p class="form-hint"><?= __('activity_log_purge_hint') ?></p>
</div>
<?php endif; ?>

<div class="card card--filters mb-sm">
    <form method="GET" action="" class="filter-bar">
        <div class="shifts-filters__row">
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-level"><?= __('level') ?></label>
                <select id="al-level" name="level" class="form-control form-control-sm" data-submit-on-change>
                    <option value=""><?= __('all') ?></option>
                    <?php foreach ($levels as $l): ?>
                        <option value="<?= $l ?>" <?= $levelFilter === $l ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($l)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-channel"><?= __('channel') ?></label>
                <select id="al-channel" name="channel" class="form-control form-control-sm" data-submit-on-change>
                    <option value=""><?= __('all') ?></option>
                    <?php foreach ($channels as $c): ?>
                        <option value="<?= $c ?>" <?= $channelFilter === $c ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($actions)): ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-action"><?= __('action') ?></label>
                <select id="al-action" name="action" class="form-control form-control-sm" data-submit-on-change>
                    <option value=""><?= __('all') ?></option>
                    <?php foreach ($actions as $a):
                        $alLabel = audit_action_label($a, $actionLabels); ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= htmlspecialchars($alLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-from"><?= __('from') ?></label>
                <input type="date" id="al-from" name="from" value="<?= htmlspecialchars($fromFilter) ?>" class="form-control form-control-sm">
            </div>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-to"><?= __('to') ?></label>
                <input type="date" id="al-to" name="to" value="<?= htmlspecialchars($toFilter) ?>" class="form-control form-control-sm">
            </div>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-user"><?= __('user') ?></label>
                <select id="al-user" name="user_id" class="form-control form-control-sm" data-submit-on-change>
                    <option value=""><?= __('all') ?></option>
                    <?php foreach ($users_map as $uid => $uname): ?>
                        <option value="<?= $uid ?>" <?= (string) $userIdFilter === (string) $uid ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="al-query"><?= __('search') ?></label>
                <input type="text" id="al-query" name="query" value="<?= htmlspecialchars($queryFilter) ?>" class="form-control form-control-sm" placeholder="...">
            </div>
            <div class="shifts-filters__actions">
                <a href="?" class="btn btn--ghost btn--sm"><?= __('reset') ?></a>
            </div>
        </div>
    </form>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination-info text-sm-muted mb-xs"><?= sprintf(__('page_of'), $page, $totalPages) ?></div>
<?php endif; ?>

<div class="card">
    <?php if (empty($rows)):
        echo EmptyState::make(__('empty_activity_log'))->render();
    else:
        $actionLabelsLocal = $actionLabels;
        // Libellés des champs modifiés : la traduction du nom de colonne quand elle existe (même nom, ou clé proche pour
        // les identifiants), sinon le nom brut. Avant : toujours le nom de colonne (start_time, hourly_rate…).
        $fieldAliases = ['shift_date' => 'date', 'store_id' => 'store', 'user_id' => 'employee', 'shift_type_id' => 'shift_type',
            'is_active' => 'active', 'pause_minutes' => 'pause', 'role_id' => 'role', 'duration_minutes' => 'duration'];
        $fieldLabel = static function (string $field) use ($fieldAliases): string {
            $key = $fieldAliases[$field] ?? $field;
            $label = __($key);
            return $label !== $key ? $label : $field;
        };
        $fieldLabels = [];
        foreach ($rows as $_row) {
            $_ctx = is_array($_row['context'] ?? null) ? $_row['context'] : [];
            foreach (array_keys(is_array($_ctx['changes'] ?? null) ? $_ctx['changes'] : []) as $_field) {
                $fieldLabels[(string) $_field] ??= $fieldLabel((string) $_field);
            }
        }
        echo Table::make()
            ->data($rows)
            ->rowAttrs(function($row) use ($actionLabelsLocal, $users_map) {
                $levelClass = match ($row['level'] ?? 'info') {
                    'debug'    => 'secondary',
                    'info'     => 'info',
                    'warning'  => 'warning',
                    'error'    => 'danger',
                    'critical' => 'danger',
                    default    => 'primary',
                };
                $action = $row['action'] ?? '';
                $label  = $action ? audit_action_label($action, $actionLabelsLocal) : '—';
                $uid    = (int) ($row['user_id'] ?? 0);
                $uname  = $uid > 0 ? ($users_map[$uid] ?? '#' . $uid) : '—';
                $context = $row['context'] ?? [];
                return [
                    'class'              => 'log-row',
                    'tabindex'           => '0',
                    'role'               => 'button',
                    'data-log-id'        => (int) ($row['id'] ?? 0),
                    'data-created-at'    => $row['created_at'] ?? '',
                    'data-level'         => $row['level'] ?? '',
                    'data-level-class'   => $levelClass,
                    'data-channel'       => $row['channel'] ?? '',
                    'data-action'        => $action,
                    'data-action-label'  => $label,
                    'data-message'       => $row['message'] ?? '',
                    'data-user-id'       => $uid,
                    'data-user-name'     => $uname,
                    'data-resource-type' => $row['resource_type'] ?? '',
                    'data-resource-id'   => (int) ($row['resource_id'] ?? 0),
                    'data-ip'            => $row['ip_address'] ?? '',
                    'data-user-agent'    => $row['user_agent'] ?? '',
                    'data-request-method'=> $row['request_method'] ?? '',
                    'data-request-uri'   => $row['request_uri'] ?? '',
                    'data-response-status'=> (int) ($row['response_status'] ?? 0),
                    'data-duration-ms'   => (int) ($row['duration_ms'] ?? 0),
                    'data-session-id'    => $row['session_id'] ?? '',
                    'data-store-id'      => (int) ($row['store_id'] ?? 0),
                    'data-context'       => htmlspecialchars(json_encode($context, JSON_UNESCAPED_UNICODE), ENT_QUOTES),
                ];
            })
            ->column(__('date_hour'), fn($row) => '<span class="td-nowrap text-sm-muted">' . htmlspecialchars($row['created_at'] ?? '') . '</span>')
            ->column(__('action'), function($row) use ($actionLabelsLocal) {
                $levelClass = match ($row['level'] ?? 'info') {
                    'debug'    => 'secondary',
                    'info'     => 'info',
                    'warning'  => 'warning',
                    'error'    => 'danger',
                    'critical' => 'danger',
                    default    => 'primary',
                };
                $action = $row['action'] ?? '';
                $label  = $action ? audit_action_label($action, $actionLabelsLocal) : '—';
                return Badge::make($row['level'] ?? '')->variant($levelClass)->render()
                    . ($action ? Badge::make($label)->primary()->render() : '—');
            })
            ->column(__('message'), function($row) use ($actionLabelsLocal, $fieldLabels) {
                $action = $row['action'] ?? '';
                $context = $row['context'] ?? [];
                $summary = $row['message'] ?? '';
                if ($context && is_array($context) && !empty($context['changes'])) {
                    $changed = array_map(fn($f) => $fieldLabels[(string) $f] ?? (string) $f, array_keys($context['changes']));
                    $summary .= ' (' . implode(', ', array_slice($changed, 0, 3)) . (count($changed) > 3 ? ', …' : '') . ')';
                }
                return '<span class="td-message">' . htmlspecialchars($summary) . '</span>';
            })
            ->column(__('user'), function($row) use ($users_map) {
                $uid   = (int) ($row['user_id'] ?? 0);
                $uname = $uid > 0 ? ($users_map[$uid] ?? '#' . $uid) : '—';
                return '<span class="text-sm-muted">' . htmlspecialchars($uname) . '</span>';
            })
            ->column(__('resource'), function($row) {
                if (empty($row['resource_type'])) return '—';
                $html = '<code>' . htmlspecialchars($row['resource_type']) . '</code>';
                if (!empty($row['resource_id'])) {
                    $html .= ' <span class="text-dim">#' . (int) $row['resource_id'] . '</span>';
                }
                return $html;
            })
            ->render();
        if ($totalPages > 1):
            echo Pagination::make($page, $totalPages)
                ->queryParams(array_filter([
                    'level'        => $levelFilter ?: null,
                    'channel'      => $channelFilter ?: null,
                    'action'       => $actionFilter ?: null,
                    'resource_type'=> $resourceFilter ?: null,
                    'from'         => $fromFilter ?: null,
                    'to'           => $toFilter ?: null,
                    'user_id'      => $userIdFilter ?: null,
                    'query'        => $queryFilter ?: null,
                ]))
                ->render();
        endif;
    endif; ?>
</div>

<?php
echo Modal::make('—', '<div id="al-detail-content"></div>')
    ->attrs(['id' => 'al-detail-modal'])
    ->wide()
    ->render();
?>

<script type="application/json" id="kintai-activity-log-data"><?= json_encode([
    'fields' => $fieldLabels ?? [],
    'labels' => [
        'changesTitle' => __('log_changes_title'),
        'field'        => __('log_field'),
        'oldValue'     => __('log_old_value'),
        'newValue'     => __('log_new_value'),
        'contextTitle' => __('log_context_title'),
        'level'       => __('level'),
        'channel'     => __('channel'),
        'date'        => __('date'),
        'user'        => __('user'),
        'store'       => __('store'),
        'resource'    => __('resource'),
        'message'     => __('message'),
        'httpRequest' => __('http_request'),
        'method'      => __('method'),
        'status'      => __('status'),
        'duration'    => __('duration'),
        'client'      => __('client'),
        'session'     => __('session'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<script nonce="<?= csp_nonce() ?>">
(function() {
    var rows = document.querySelectorAll('.log-row');
    var content = document.getElementById('al-detail-content');
    var title   = document.getElementById('al-modal-title');
    var DATA = JSON.parse(document.getElementById('kintai-activity-log-data').textContent);
    var LBL = DATA.labels;
    var FIELDS = DATA.fields || {};

    function escapeHtml(str) {
        if (str == null) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function val(v) { return v && v !== '0' && v !== '' ? escapeHtml(v) : '<span class="text-dim">—</span>'; }

    function renderDiff(changes) {
        if (!changes || Object.keys(changes).length === 0) return '';
        var rows = Object.keys(changes).map(function(field) {
            var c = changes[field];
            var oldVal = c.old != null ? escapeHtml(String(c.old)) : '<span class="text-dim">null</span>';
            var newVal = c.new != null ? escapeHtml(String(c.new)) : '<span class="text-dim">null</span>';
            return '<tr>' +
                '<td class="td-nowrap"><strong>' + escapeHtml(FIELDS[field] || field) + '</strong></td>' +
                '<td class="al-diff-old">' + oldVal + '</td>' +
                '<td class="al-diff-new">' + newVal + '</td>' +
            '</tr>';
        }).join('');
        return '<h4 class="mb-xs">' + escapeHtml(LBL.changesTitle) + '</h4>' +
            '<div class="table-wrap"><table class="data-table">' +
            '<thead><tr><th>' + escapeHtml(LBL.field) + '</th><th>' + escapeHtml(LBL.oldValue) + '</th><th>' + escapeHtml(LBL.newValue) + '</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table></div>';
    }

    function renderContext(context) {
        if (!context || typeof context !== 'object') return '';
        var excludes = ['changes'];
        var items = Object.keys(context).filter(function(k) { return excludes.indexOf(k) === -1; });
        if (items.length === 0) return '';

        var list = items.map(function(k) {
            var v = context[k];
            if (v === null || v === '') return null;
            var display = typeof v === 'object' ? escapeHtml(JSON.stringify(v, null, 2)) : escapeHtml(String(v));
            return '<div class="mb-xs"><code>' + escapeHtml(k) + '</code>: ' + display + '</div>';
        }).filter(Boolean).join('');
        if (!list) return '';
        return '<h4 class="mb-xs">' + escapeHtml(LBL.contextTitle) + '</h4><div class="al-context">' + list + '</div>';
    }

    function showDetail(row) {
        var ctx = {};
        try { ctx = JSON.parse(row.getAttribute('data-context') || '{}'); } catch(e) {}
        var actionLabel = row.getAttribute('data-action-label') || '—';
        var level   = row.getAttribute('data-level') || '';
        var lc      = row.getAttribute('data-level-class') || 'info';

        title.textContent = actionLabel;

        var html = '';

        // Métadonnées principales
        html += '<div class="al-meta">';
        html += '<div class="al-meta-item"><span class="text-dim">' + LBL.level + '</span><span class="badge badge--' + lc + '">' + escapeHtml(level) + '</span></div>';
        html += '<div class="al-meta-item"><span class="text-dim">' + LBL.channel + '</span><code>' + val(row.getAttribute('data-channel')) + '</code></div>';
        html += '<div class="al-meta-item"><span class="text-dim">' + LBL.date + '</span>' + val(row.getAttribute('data-created-at')) + '</div>';
        html += '<div class="al-meta-item"><span class="text-dim">' + LBL.user + '</span>' + val(row.getAttribute('data-user-name')) + '</div>';
        var storeId = row.getAttribute('data-store-id');
        if (storeId && storeId !== '0') {
            html += '<div class="al-meta-item"><span class="text-dim">' + LBL.store + '</span>#' + escapeHtml(storeId) + '</div>';
        }
        var resType = row.getAttribute('data-resource-type');
        var resId   = row.getAttribute('data-resource-id');
        if (resType) {
            html += '<div class="al-meta-item"><span class="text-dim">' + LBL.resource + '</span><code>' + escapeHtml(resType) + '</code> ' + (resId && resId !== '0' ? '#' + escapeHtml(resId) : '') + '</div>';
        }
        html += '<div class="al-meta-item"><span class="text-dim">' + LBL.message + '</span><em>' + val(row.getAttribute('data-message')) + '</em></div>';
        html += '</div>';

        // Requête HTTP
        var method = row.getAttribute('data-request-method');
        var uri    = row.getAttribute('data-request-uri');
        var status = row.getAttribute('data-response-status');
        var dur    = row.getAttribute('data-duration-ms');
        if (method || uri) {
            html += '<h4 class="mb-xs mt-sm">' + LBL.httpRequest + '</h4><div class="al-meta">';
            if (method) html += '<div class="al-meta-item"><span class="text-dim">' + LBL.method + '</span>' + val(method) + '</div>';
            if (uri)    html += '<div class="al-meta-item"><span class="text-dim">URI</span><code>' + val(uri) + '</code></div>';
            if (status && status !== '0') html += '<div class="al-meta-item"><span class="text-dim">' + LBL.status + '</span>' + escapeHtml(status) + '</div>';
            if (dur && dur !== '0')       html += '<div class="al-meta-item"><span class="text-dim">' + LBL.duration + '</span>' + escapeHtml(dur) + ' ms</div>';
            html += '</div>';
        }

        // IP & User-Agent
        var ip  = row.getAttribute('data-ip');
        var ua  = row.getAttribute('data-user-agent');
        var sid = row.getAttribute('data-session-id');
        if (ip || ua || sid) {
            html += '<h4 class="mb-xs mt-sm">' + LBL.client + '</h4><div class="al-meta">';
            if (ip)  html += '<div class="al-meta-item"><span class="text-dim">IP</span><code>' + val(ip) + '</code></div>';
            if (sid) html += '<div class="al-meta-item"><span class="text-dim">' + LBL.session + '</span><code>' + val(sid) + '</code></div>';
            if (ua)  html += '<div class="al-meta-item al-meta-item--full"><span class="text-dim">User-Agent</span><span class="text-hint">' + escapeHtml(ua.length > 200 ? ua.substring(0, 200) + '…' : ua) + '</span></div>';
            html += '</div>';
        }

        // Diff (before/after)
        html += renderDiff(ctx.changes);

        // Autre contexte
        html += renderContext(ctx);

        content.innerHTML = html;
    }

    rows.forEach(function(row) {
        row.addEventListener('click', function(e) {
            showDetail(this);
            openModal('al-detail-modal');
        });
        row.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                showDetail(this);
                openModal('al-detail-modal');
            }
        });
    });
})();
</script>
