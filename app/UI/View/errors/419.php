<?php
// Jeton CSRF refusé alors que l'utilisateur est toujours connecté (voir CsrfMiddleware::rejected()). Rendue avant
// AppSettingsMiddleware, qui n'a donc pas encore partagé $BASE_URL : base_url() directement (installation en
// sous-dossier). Le lien renvoie vers la page d'origine, rechargée avec un jeton valide.
$base = base_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('error_419_title') ?></title>
    <link rel="stylesheet" href="<?= $base ?>/assets/css/app.css?v=<?= asset_version() ?>">
</head>
<body class="error-body">
    <div class="error-page">
        <img src="<?= $base ?>/assets/img/<?= mascot_path('http-error/403') ?>" alt="<?= __('mascot_alt') ?>" class="error-mascot">
        <div class="error-code">419</div>
        <p class="error-message"><?= __('error_419_message') ?></p>
        <a href="<?= htmlspecialchars(back_url($base . '/'), ENT_QUOTES) ?>" class="error-link">← <?= __('error_419_back') ?></a>
    </div>
</body>
</html>
