<?php
/**
 * Template HTML for mPDF — Employee export (item 3).
 * Rendered standalone (no layout) : soit pour la génération PDF serveur,
 * soit directement comme aperçu navigateur (avec barre d'outils) quand
 * $downloadUrl est fourni — voir AdminUserController::exportUsersPdf() vs
 * exportUsersPdfDownload().
 *
 * @var array       $rows          Voir AdminUserController::usersForExport()
 * @var string      $generated_at
 * @var string|null $downloadUrl
 */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style nonce="<?= csp_nonce() ?>">
<?php
echo file_get_contents(dirname(__DIR__, 4) . '/public/assets/css/pdf/pdf-brand.css');
echo file_get_contents(dirname(__DIR__, 4) . '/public/assets/css/pdf/pdf-preview.css');
echo file_get_contents(dirname(__DIR__, 4) . '/public/assets/css/pdf/pdf-export-table.css');
?>
</style>
</head>
<body>

<?php include __DIR__ . '/../_partials/_pdf-preview-toolbar.php'; ?>
<div class="pdf-preview-page pdf-preview-page--landscape">

<h1><?= __('users') ?></h1>
<div class="subtitle"><?= count($rows) ?> — <?= htmlspecialchars($generated_at) ?></div>

<table>
    <tr>
        <th><?= __('employee_number') ?></th>
        <th><?= __('name') ?></th>
        <th><?= __('email') ?></th>
        <th><?= __('phone') ?></th>
        <th><?= __('mobile_phone') ?></th>
        <th><?= __('address') ?></th>
        <th><?= __('store') ?></th>
        <th><?= __('status') ?></th>
        <th><?= __('hourly_rate') ?></th>
    </tr>
    <?php foreach ($rows as $row): ?>
    <tr>
        <td><?= htmlspecialchars($row['employee_code'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['display_name'] ?: trim($row['last_name'] . ' ' . $row['first_name'])) ?></td>
        <td><?= htmlspecialchars($row['email']) ?></td>
        <td><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['mobile_phone'] ?? '—') ?></td>
        <td><?= nl2br(htmlspecialchars(trim(($row['postal_code'] ? $row['postal_code'] . ' ' : '') . ($row['address'] ?? '')))) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?= !empty($row['stores']) ? htmlspecialchars(implode(', ', $row['stores'])) : '<span class="text-muted">—</span>' ?></td>
        <td><?= $row['is_active'] ? __('active') : __('inactive') ?></td>
        <td>
            <?php if (empty($row['hourly_rates'])): ?>
                <span class="text-muted">—</span>
            <?php else: ?>
                <?php foreach ($row['hourly_rates'] as $rate): ?>
                    <?= htmlspecialchars($rate['shift_type']) ?>: <?= number_format($rate['hourly_rate'], 0) ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<div class="footer">
    <?= __('pdf_generated_by') ?> Kintai — <?= htmlspecialchars($generated_at) ?>
</div>

</div>
</body>
</html>
