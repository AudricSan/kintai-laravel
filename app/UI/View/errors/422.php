<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('error_422_title') ?></title>
    <link rel="stylesheet" href="<?= ($BASE_URL ?? '') ?>/assets/css/app.css?v=<?= asset_version() ?>">
</head>
<body class="error-body">
    <div class="error-page">
        <img src="<?= ($BASE_URL ?? '') ?>/assets/img/<?= mascot_path('http-error/422') ?>" alt="<?= __('mascot_alt') ?>" class="error-mascot">
        <div class="error-code">422</div>
        <p class="error-message"><?= htmlspecialchars($message ?? __('error_422_message')) ?></p>
        <a href="<?= ($BASE_URL ?? '') ?>/" class="error-link">← <?= __('back_to_dashboard') ?></a>
    </div>
</body>
</html>
