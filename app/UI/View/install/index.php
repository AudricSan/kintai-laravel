<?php
/**
 * Page de l'installateur web (public/install.php). Autonome : l'application n'est pas démarrée, pas de layout ni de
 * jeton CSRF (aucune session avant l'installation). Aucun style ni script en ligne : public/assets/css/src/features/install.css
 * et public/assets/js/modules/install.js.
 *
 * @var string                $BASE_URL
 * @var string                $locale
 * @var list<array>           $languages
 * @var list<array>           $checks       voir InstallRequirements::checks()
 * @var bool                  $can_install
 * @var list<string>          $drivers      pilotes disponibles
 * @var list<string>          $errors       messages déjà traduits
 * @var array|null            $result       voir WebInstaller::install()
 * @var string                $setup_url
 * @var string                $demo_password
 * @var array<string, mixed>  $old          valeurs envoyées ($_POST), jamais les mots de passe
 */
use kintai\UI\Components\Alert;
use kintai\UI\Components\Button;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$field = static fn (string $key, string $default = ''): string => is_string($old[$key] ?? null) ? $old[$key] : $default;
$driver = $field('db_driver', in_array('sqlite', $drivers, true) ? 'sqlite' : ($drivers[0] ?? 'sqlite'));
$failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['ok']));
$blocking = array_values(array_filter($failed, static fn (array $c): bool => $c['blocking']));
?>
<!DOCTYPE html>
<html lang="<?= $e($locale) ?>" data-mascot="kitsune">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $e(__('install_title')) ?> — Kintai</title>
    <link rel="icon" href="<?= $BASE_URL ?>/assets/img/favicon.ico" sizes="any">
    <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css?v=<?= asset_version() ?>">
</head>
<body class="guest-layout">
<div class="guest-main">
    <div class="guest-container guest-container--wide">
        <div class="guest-brand">
            <img src="<?= $BASE_URL ?>/assets/img/mascot/kitsune/login.png" alt="<?= $e(__('mascot_alt')) ?>" class="guest-brand__mascot">
            <h1>Kintai</h1>
            <p><?= $e(__('install_subtitle')) ?></p>
        </div>

        <div class="guest-card">
        <?php if ($result !== null): ?>

            <?= Alert::make(__('install_success'))->success()->render() ?>

            <?php if ($result['demo_accounts'] !== []): ?>
                <?= Alert::make(__('install_demo_accounts_warning'))->warning()->render() ?>
                <p class="install-text"><?= $e(__('install_demo_accounts_intro', ['password' => $demo_password])) ?></p>
                <ul class="install-accounts">
                    <?php foreach ($result['demo_accounts'] as $account): ?>
                        <li class="install-accounts__item">
                            <span><?= $e($account['name']) ?> <small class="install-accounts__role"><?= $e(__('role_' . $account['role'])) ?></small></span>
                            <code><?= $e($account['email']) ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="install-text"><?= $e(__('install_redirecting')) ?></p>
            <?php endif; ?>

            <?= Button::make(__('install_continue'))->primary()->full()->link($setup_url)->render() ?>

        <?php else: ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert--danger" role="alert">
                    <ul class="install-errors">
                        <?php foreach ($errors as $error): ?>
                            <li><?= $e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <details class="install-checks<?= $blocking !== [] ? ' install-checks--blocked' : '' ?>"<?= $failed !== [] ? ' open' : '' ?>>
                <summary class="install-checks__summary">
                    <?php if ($blocking !== []): ?>
                        <?= $e(__('install_req_blocked')) ?>
                    <?php elseif ($failed !== []): ?>
                        <?= $e(__('install_req_warnings')) ?>
                    <?php else: ?>
                        <?= $e(__('install_req_ok')) ?>
                    <?php endif; ?>
                </summary>
                <ul class="install-checks__list">
                    <?php foreach ($checks as $check): ?>
                        <?php $state = $check['ok'] ? 'ok' : ($check['blocking'] ? 'fail' : 'warn'); ?>
                        <li class="install-check install-check--<?= $state ?>">
                            <span class="install-check__icon" aria-hidden="true"><?= $state === 'ok' ? '✓' : ($state === 'fail' ? '✕' : '!') ?></span>
                            <span><?= $e(__($check['key'], ['name' => $check['label']])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <form method="POST" action="" class="form-stack" data-install-form>

                <?php if ($languages !== []): ?>
                <section class="install-section">
                    <h2 class="install-section__title"><?= $e(__('install_section_language')) ?></h2>
                    <div class="form-group">
                        <label class="form-label" for="app_locale"><?= $e(__('install_language_label')) ?></label>
                        <select id="app_locale" name="app_locale" class="form-control" required data-install-language>
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?= $e($lang['code']) ?>" lang="<?= $e($lang['code']) ?>" <?= $locale === $lang['code'] ? 'selected' : '' ?>><?= $e($lang['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint"><?= $e(__('install_language_hint')) ?></p>
                    </div>
                </section>
                <?php endif; ?>

                <section class="install-section">
                    <h2 class="install-section__title"><?= $e(__('install_section_owner')) ?></h2>
                    <div class="install-row">
                        <div class="form-group">
                            <label class="form-label form-label--required" for="admin_first_name"><?= $e(__('first_name')) ?></label>
                            <input type="text" id="admin_first_name" name="admin_first_name" class="form-control" value="<?= $e($field('admin_first_name')) ?>" autocomplete="given-name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label form-label--required" for="admin_last_name"><?= $e(__('last_name')) ?></label>
                            <input type="text" id="admin_last_name" name="admin_last_name" class="form-control" value="<?= $e($field('admin_last_name')) ?>" autocomplete="family-name" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="admin_email"><?= $e(__('email_address')) ?></label>
                        <input type="email" id="admin_email" name="admin_email" class="form-control" value="<?= $e($field('admin_email')) ?>" autocomplete="email" required>
                    </div>
                    <div class="install-row">
                        <div class="form-group">
                            <label class="form-label form-label--required" for="admin_password"><?= $e(__('password')) ?></label>
                            <input type="password" id="admin_password" name="admin_password" class="form-control" minlength="8" autocomplete="new-password" required>
                            <p class="form-hint"><?= $e(__('install_password_hint')) ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label form-label--required" for="admin_password_confirmation"><?= $e(__('install_password_confirm')) ?></label>
                            <input type="password" id="admin_password_confirmation" name="admin_password_confirmation" class="form-control" minlength="8" autocomplete="new-password" required>
                        </div>
                    </div>
                </section>

                <section class="install-section">
                    <h2 class="install-section__title"><?= $e(__('install_section_database')) ?></h2>
                    <div class="install-drivers" role="radiogroup" aria-label="<?= $e(__('install_section_database')) ?>">
                        <?php foreach (['sqlite' => 'SQLite', 'mysql' => 'MySQL / MariaDB'] as $value => $label): ?>
                            <?php $available = in_array($value, $drivers, true); ?>
                            <label class="install-driver<?= $available ? '' : ' install-driver--disabled' ?>">
                                <input type="radio" name="db_driver" value="<?= $value ?>" class="install-driver__input" data-install-driver
                                    <?= $driver === $value && $available ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?>>
                                <span class="install-driver__label"><?= $label ?></span>
                                <small class="install-driver__hint"><?= $e($available ? __('install_driver_' . $value . '_hint') : __('install_driver_unavailable', ['name' => \kintai\Core\Install\InstallRequirements::DRIVER_EXTENSIONS[$value]])) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="install-mysql" data-install-mysql<?= $driver === 'mysql' ? '' : ' hidden' ?>>
                        <div class="install-row install-row--host">
                            <div class="form-group">
                                <label class="form-label" for="mysql_host"><?= $e(__('install_mysql_host')) ?></label>
                                <input type="text" id="mysql_host" name="mysql_host" class="form-control" value="<?= $e($field('mysql_host', $default_host)) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="mysql_port"><?= $e(__('install_mysql_port')) ?></label>
                                <input type="number" id="mysql_port" name="mysql_port" class="form-control" min="1" max="65535" value="<?= $e($field('mysql_port', $default_port)) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="mysql_database"><?= $e(__('install_mysql_database')) ?></label>
                            <input type="text" id="mysql_database" name="mysql_database" class="form-control" pattern="[A-Za-z0-9_$]{1,64}" value="<?= $e($field('mysql_database', $default_database)) ?>">
                            <p class="form-hint"><?= $e(__('install_mysql_database_hint')) ?></p>
                        </div>
                        <div class="install-row">
                            <div class="form-group">
                                <label class="form-label" for="mysql_username"><?= $e(__('install_mysql_username')) ?></label>
                                <input type="text" id="mysql_username" name="mysql_username" class="form-control" value="<?= $e($field('mysql_username', $default_username)) ?>" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="mysql_password"><?= $e(__('password')) ?></label>
                                <input type="password" id="mysql_password" name="mysql_password" class="form-control" autocomplete="off">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="install-section">
                    <h2 class="install-section__title"><?= $e(__('install_section_options')) ?></h2>
                    <label class="form-check">
                        <input type="checkbox" name="run_seeds" value="1" <?= isset($old['run_seeds']) ? 'checked' : '' ?>>
                        <?= $e(__('install_demo_label')) ?>
                    </label>
                    <p class="form-hint install-demo-hint"><?= $e(__('install_demo_hint')) ?></p>
                </section>

                <?= Button::make(__('install_submit'))->primary()->full()->submit()->disabled(!$can_install)->render() ?>
            </form>

        <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?= $BASE_URL ?>/assets/js/modules/install.js?v=<?= asset_version() ?>"></script>
</body>
</html>
