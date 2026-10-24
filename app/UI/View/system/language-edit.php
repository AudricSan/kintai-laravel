<?php
use kintai\UI\Components\FilterBar;
use kintai\UI\Components\Pagination;

/** @var array  $language */
/** @var array  $items */
/** @var int    $total */
/** @var string $search */
/** @var int    $page */
/** @var int    $perPage */
/** @var string $BASE_URL */

$code = $language['code'];
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('edit_translations') ?> — <?= htmlspecialchars($language['name']) ?></h2>
    <div class="page-header__actions">
        <a href="<?= back_url($BASE_URL . '/admin/languages') ?>" class="btn btn--ghost btn--sm">← <?= __('back') ?></a>
    </div>
</div>

<?= FilterBar::make()
    ->action($BASE_URL . '/admin/languages/' . $code . '/edit')
    ->search('q', __('search_translations'), $search)
    ->render() ?>

<div class="card">
    <div class="table-wrap">
        <table class="data-table" data-mob-stack>
            <thead>
                <tr>
                    <th><?= __('translation_key') ?></th>
                    <th><?= __('translation_reference') ?></th>
                    <th><?= __('translation_value') ?></th>
                    <th class="td-center"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody id="translation-rows">
                <?php $lastPrefix = null; ?>
                <?php foreach ($items as $item): ?>
                    <?php
                        $prefix = explode('_', $item['key'], 2)[0];
                        if ($prefix !== $lastPrefix):
                            $lastPrefix = $prefix;
                    ?>
                    <tr class="translation-row__group">
                        <td colspan="4"><?= htmlspecialchars($prefix) ?></td>
                    </tr>
                    <?php endif; ?>
                <tr data-key="<?= htmlspecialchars($item['key']) ?>">
                    <td data-label="<?= htmlspecialchars(__('translation_key')) ?>" class="td-nowrap"><code class="text-xs"><?= htmlspecialchars($item['key']) ?></code></td>
                    <td data-label="<?= htmlspecialchars(__('translation_reference')) ?>" class="text-xs text-muted"><?= htmlspecialchars($item['reference']) ?></td>
                    <td data-label="<?= htmlspecialchars(__('translation_value')) ?>">
                        <textarea class="form-control form-control-sm translation-value-input" rows="1"><?= htmlspecialchars($item['value']) ?></textarea>
                    </td>
                    <td data-label="<?= htmlspecialchars(__('actions')) ?>" class="td-center td-nowrap">
                        <button type="button" class="btn btn--danger btn--xs translation-delete-btn"><?= __('delete_key') ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="4" class="empty-state"><?= __('translation_no_results') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= Pagination::make($page, (int) ceil($total / $perPage))->queryParams(['q' => $search])->render() ?>

<div class="card mt-sm">
    <div class="card-body">
        <h3 class="card-title"><?= __('add_custom_key') ?></h3>
        <div class="form-flex">
            <div class="form-group">
                <label class="form-label"><?= __('translation_key') ?></label>
                <input type="text" id="new-key-input" class="form-control form-control-sm" pattern="[a-zA-Z0-9_.]+">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('translation_value') ?></label>
                <input type="text" id="new-value-input" class="form-control form-control-sm">
            </div>
            <div class="form-group">
                <button type="button" id="add-key-btn" class="btn btn--primary btn--sm"><?= __('add_custom_key') ?></button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="lang-edit-data"><?= json_encode([
    'baseUrl'      => rtrim($BASE_URL, '/'),
    'csrfToken'    => csrf_token(),
    'code'         => $code,
    'confirmDelete' => __('translation_confirm_delete_key'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/language-editor.js"></script>
