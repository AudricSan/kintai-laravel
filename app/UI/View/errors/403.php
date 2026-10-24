<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('error_403_title') ?></title>
    <link rel="stylesheet" href="<?= ($BASE_URL ?? '') ?>/assets/css/app.css?v=<?= asset_version() ?>">
</head>
<body class="error-body">
    <div class="error-page">
        <img src="<?= ($BASE_URL ?? '') ?>/assets/img/<?= mascot_path('http-error/403') ?>" alt="<?= __('mascot_alt') ?>" class="error-mascot">
        <div class="error-code">403</div>
        <p class="error-message"><?= __('error_403_message') ?></p>
        <?php // Détail technique (ex. « Permission requise : shifts.import ») : utile
              // pour signaler au Owner quelle clé manque au rôle, affiché discrètement. ?>
        <?php if (!empty($message)): ?>
        <p class="error-detail"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <a href="<?= ($BASE_URL ?? '') ?>/" class="error-link">← <?= __('back_to_dashboard') ?></a>
    </div>
</body>
</html>
