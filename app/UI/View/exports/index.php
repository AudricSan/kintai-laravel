<?php
/**
 * Page « Exports » : choix du jeu de données et de la cible (moi, un magasin, tous mes magasins) dans un premier formulaire
 * qui se recharge au changement, puis personnes, période et format dans le second, qui télécharge le fichier.
 *
 * @var array                                              $available  clé => ['dataset' => ExportDataset, 'stores' => list<int>, 'self' => bool]
 * @var \kintai\Contract\Extension\ExportDataset|null      $dataset    jeu de données choisi
 * @var array<string, string>                              $targets    valeur => libellé
 * @var string                                             $target     cible choisie (me, all, store:ID)
 * @var list<array<string, mixed>>                         $candidates personnes proposables (magasin choisi)
 * @var string                                             $from
 * @var string                                             $to
 * @var bool                                               $canIcs
 * @var string                                             $BASE_URL
 */

use kintai\UI\Components\Card;
use kintai\UI\Components\UserPicker;

$notice = in_array($_GET['error'] ?? '', ['forbidden', 'ics_scope'], true) ? (string) $_GET['error'] : '';
?>

<div class="page-header">
    <h2 class="page-header__title"><?= __('export_title') ?></h2>
</div>

<?php if ($notice !== ''): ?>
    <div class="alert alert--warning mb-sm"><?= __('export_error_' . $notice) ?></div>
<?php endif; ?>

<?php if ($available === [] || $dataset === null): ?>
    <p class="text-muted"><?= __('export_nothing') ?></p>
<?php else: ?>
    <?php ob_start(); ?>
    <p class="text-muted mb-sm"><?= __('export_description') ?></p>

    <form method="GET" action="<?= $BASE_URL ?>/exports" class="form-stack">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="export-dataset"><?= __('export_dataset') ?></label>
                <select class="form-control" id="export-dataset" name="dataset" data-submit-on-change>
                    <?php foreach ($available as $key => $entry): ?>
                        <option value="<?= htmlspecialchars((string) $key) ?>"<?= $key === $dataset->key ? ' selected' : '' ?>><?= htmlspecialchars(__($entry['dataset']->label)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="export-target"><?= __('export_target') ?></label>
                <select class="form-control" id="export-target" name="target" data-submit-on-change>
                    <?php foreach ($targets as $value => $label): ?>
                        <option value="<?= htmlspecialchars((string) $value) ?>"<?= $value === $target ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <form method="GET" action="<?= $BASE_URL ?>/exports/download" class="form-stack">
        <input type="hidden" name="dataset" value="<?= htmlspecialchars($dataset->key) ?>">
        <input type="hidden" name="target" value="<?= htmlspecialchars($target) ?>">

        <?php if ($candidates !== []): ?>
            <div class="form-group">
                <?= UserPicker::make('user_ids', $candidates)
                    ->legend(__('export_people'))
                    ->hint(__('export_people_hint'))
                    ->render() ?>
            </div>
        <?php endif; ?>

        <?php if ($dataset->hasPeriod): ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="export-from"><?= __('export_from') ?></label>
                    <input type="date" class="form-control" id="export-from" name="from" value="<?= htmlspecialchars($from) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="export-to"><?= __('export_to') ?></label>
                    <input type="date" class="form-control" id="export-to" name="to" value="<?= htmlspecialchars($to) ?>">
                </div>
            </div>
        <?php endif; ?>

        <div class="form-inline-flex">
            <button type="submit" name="format" value="xlsx" class="btn btn--primary btn--sm"><?= __('export_format_xlsx') ?></button>
            <button type="submit" name="format" value="csv" class="btn btn--ghost btn--sm"><?= __('export_format_csv') ?></button>
            <button type="submit" name="format" value="json" class="btn btn--ghost btn--sm"><?= __('export_format_json') ?></button>
            <?php if ($canIcs): ?>
                <button type="submit" name="format" value="ics" class="btn btn--ghost btn--sm"><?= __('export_format_ics') ?></button>
            <?php endif; ?>
        </div>
        <?php if ($canIcs): ?>
            <p class="text-xs text-muted"><?= __('export_ics_hint') ?></p>
        <?php endif; ?>
    </form>
    <?php
    echo Card::make(__('export_card'))->body(ob_get_clean())->render();
    ?>
<?php endif; ?>
