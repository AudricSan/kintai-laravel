<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/** @var array       $all_stores */
/** @var string|null $error */

echo Flash::fromQuery('error', [
    'upload' => __('upload_error'),
    'parse'  => __('parse_error'),
    'empty'  => __('empty_file_error'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('import_excel_title') ?></h2>
    <a href="<?= back_url(route_url('admin.shifts')) ?>" class="btn btn--ghost">← <?= __('back') ?></a>
</div>

<?php
$action = route_url('admin.shifts.import');
ob_start();
?>
<form method="POST" action="<?= $action ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label"><?= __('destination_store') ?> <span class="required">*</span></label>
        <select name="store_id" class="form-control" required>
            <option value="">— <?= __('select') ?> —</option>
            <?php foreach ($all_stores as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['name'] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group import-form-group--mt">
        <label class="form-label"><?= __('excel_file_label') ?> <span class="required">*</span></label>
        <input type="file" name="excel_file" accept=".xlsx,.xlsm" required>
        <div class="form-hint"><?= __('excel_format_hint') ?></div>
    </div>
    <div class="import-form-footer">
        <?= Button::make(__('analyze_file'))->primary()->submit()->render() ?>
    </div>
</form>
<?php
$body = ob_get_clean();
echo Card::make()->narrow()->header(__('excel_file_label'))->body($body)->render();
?>
