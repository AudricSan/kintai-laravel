<?php
/**
 * Bandeau d'onglets partagé par les pages "Paramètres" (identité, sauvegarde, mises à jour, langues).
 * @var string|null $BASE_URL
 */

$_uri  = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$_base = rtrim($BASE_URL ?? '', '/');
$_settingsPath = $_base !== '' && str_starts_with($_uri, $_base) ? substr($_uri, strlen($_base)) : $_uri;
$_settingsPath = '/' . trim($_settingsPath, '/') ?: '/';

$_settingsTabs = [
    ['label' => __('settings'),  'url' => route_url('admin.owner_settings'), 'match' => '/admin/owner-settings'],
];
$_settingsTabs[] = ['label' => __('notifications'), 'url' => route_url('admin.notifications_settings'), 'match' => '/admin/notifications-settings'];
$_settingsTabs[] = ['label' => __('gcal_admin_tab'), 'url' => route_url('admin.calendar_settings'), 'match' => '/admin/calendar-settings'];
$_settingsTabs[] = ['label' => __('backup'),   'url' => route_url('admin.backup'),        'match' => '/admin/backup'];
$_settingsTabs[] = ['label' => __('update'),    'url' => route_url('admin.update'),        'match' => '/admin/update'];
$_settingsTabs[] = ['label' => __('languages'), 'url' => route_url('admin.languages'),     'match' => '/admin/languages'];
$_settingsTabs[] = ['label' => __('bundle_settings'), 'url' => route_url('admin.bundles'), 'match' => '/admin/bundles'];
$_settingsTabs[] = ['label' => __('roles'), 'url' => route_url('admin.roles'), 'match' => '/admin/roles'];
$_settingsTabs[] = ['label' => __('license'), 'url' => route_url('admin.license'), 'match' => '/admin/license'];
?>
<div class="tabs card--mb">
    <?php foreach ($_settingsTabs as $_t): ?>
        <a href="<?= $_t['url'] ?>" class="tab<?= str_starts_with($_settingsPath, $_t['match']) ? ' tab--active' : '' ?>">
            <?= $_t['label'] ?>
        </a>
    <?php endforeach; ?>
</div>
