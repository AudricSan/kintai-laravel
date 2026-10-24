<?php

use kintai\UI\Components\Alert;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/** @var array  $settings */
/** @var bool   $success */
/** @var string|null $error */
/** @var bool   $public_url_forced_by_env */
/** @var string|null $public_url_effective */
/** @var string|null $public_url_suggestion */
/** @var array  $theme_colors */
/** @var array  $theme_colors_dark */
/** @var string $theme_dark_mode */

/** Groupes de couleurs personnalisables (voir kintai\Core\Services\ThemeColorPalette). */
$themeGroups = [
    'primary'         => ['label' => 'primary_color',                'hint' => 'primary_color_hint'],
    'accent'          => ['label' => 'theme_accent_color',           'hint' => 'theme_accent_color_hint'],
    'table_highlight' => ['label' => 'theme_table_highlight_color',  'hint' => 'theme_table_highlight_color_hint'],
    'success'         => ['label' => 'theme_success_color',          'hint' => 'theme_success_color_hint'],
    'warning'         => ['label' => 'theme_warning_color',          'hint' => 'theme_warning_color_hint'],
    'danger'          => ['label' => 'theme_danger_color',           'hint' => 'theme_danger_color_hint'],
    'info'            => ['label' => 'theme_info_color',             'hint' => 'theme_info_color_hint'],
];

/**
 * Les 3 couleurs personnalisables réellement issues de la mascotte (les 4 couleurs
 * sémantiques success/warning/danger/info n'en font pas partie) : regroupées dans une
 * seule pastille de référence, plutôt que répétées sous chaque champ concerné.
 */
$foxyPalette = [
    ['css' => 'fur',       'color' => '#ff9f4a', 'label' => 'theme_foxy_swatch_fur'],
    ['css' => 'nose',      'color' => '#5b4a3a', 'label' => 'theme_foxy_swatch_nose'],
    ['css' => 'belly',     'color' => '#fff5e6', 'label' => 'theme_foxy_swatch_belly'],
    ['css' => 'bag',       'color' => '#4caf50', 'label' => 'theme_foxy_swatch_bag'],
    ['css' => 'highlight', 'color' => '#dff5e1', 'label' => 'theme_foxy_swatch_highlight'],
];

/** Palette officielle de la mascotte Tanuki (docs/brand/Tanuki/tanuki_planche_reference_character_design.png). */
$tanukiPalette = [
    ['css' => 'fur',       'color' => '#8b6b4f', 'label' => 'theme_tanuki_swatch_fur'],
    ['css' => 'nose',      'color' => '#5a4636', 'label' => 'theme_tanuki_swatch_nose'],
    ['css' => 'belly',     'color' => '#f7f3e7', 'label' => 'theme_tanuki_swatch_belly'],
    ['css' => 'bag',       'color' => '#4caf50', 'label' => 'theme_tanuki_swatch_bag'],
    ['css' => 'highlight', 'color' => '#e8f5e9', 'label' => 'theme_tanuki_swatch_highlight'],
];

echo Flash::fromQuery('success', ['default' => __('save_success')])->render();
if (($error ?? null) === 'public_url_invalid') {
    echo Alert::make(__('public_url_invalid'))->danger()->render();
}
if (($error ?? null) === 'mail_from_invalid') {
    echo Alert::make(__('mail_from_invalid'))->danger()->render();
}
if (($error ?? null) === 'instance_locale_not_saved') {
    echo Alert::make(__('instance_locale_not_saved'))->danger()->render();
}
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('owner_settings') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>

<form method="POST" action="<?= route_url('admin.owner_settings') ?>" class="app-settings">
    <?= csrf_field() ?>

    <?php
    ob_start();
    ?>
    <div class="form-group">
        <label class="form-label"><?= __('company_name') ?></label>
        <input type="text" name="app_subtitle" class="form-control"
            maxlength="100"
            placeholder="<?= __('company_name_placeholder') ?>"
            value="<?= htmlspecialchars($settings['app_subtitle'] ?? '', ENT_QUOTES) ?>">
        <p class="form-hint"><?= __('company_name_hint') ?></p>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('support_email') ?></label>
        <input type="email" name="app_support_email" class="form-control"
            placeholder="<?= __('support_email_placeholder') ?>"
            value="<?= htmlspecialchars($settings['app_support_email'] ?? '', ENT_QUOTES) ?>">
        <p class="form-hint"><?= __('support_email_hint') ?></p>
    </div>

    <div class="form-group">
        <label class="form-label" for="mail_from_address"><?= __('mail_from_address') ?></label>
        <input type="email" id="mail_from_address" name="mail_from_address" class="form-control"
            maxlength="190"
            placeholder="<?= __('mail_from_address_placeholder') ?>"
            value="<?= htmlspecialchars($settings['mail_from_address'] ?? '', ENT_QUOTES) ?>">
        <p class="form-hint"><?= __('mail_from_address_hint') ?></p>
    </div>

    <div class="form-group">
        <label class="form-label" for="mail_from_name"><?= __('mail_from_name') ?></label>
        <input type="text" id="mail_from_name" name="mail_from_name" class="form-control"
            maxlength="100"
            placeholder="Kintai"
            value="<?= htmlspecialchars($settings['mail_from_name'] ?? '', ENT_QUOTES) ?>">
    </div>

    <?php if (!empty($instance_languages)): ?>
    <div class="form-group">
        <label class="form-label" for="app_locale"><?= __('instance_locale') ?></label>
        <select id="app_locale" name="app_locale" class="form-control">
            <?php foreach ($instance_languages as $_lang): ?>
            <option value="<?= htmlspecialchars($_lang['code'], ENT_QUOTES) ?>" <?= ($instance_locale ?? '') === $_lang['code'] ? 'selected' : '' ?>><?= htmlspecialchars($_lang['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint"><?= __('instance_locale_hint') ?></p>
    </div>
    <?php endif; ?>

    <div class="form-group">
        <label class="form-label" for="app_public_url"><?= __('public_url') ?></label>
        <?php if (!empty($public_url_forced_by_env)): ?>
            <input type="url" id="app_public_url" class="form-control" disabled
                value="<?= htmlspecialchars((string) $public_url_effective, ENT_QUOTES) ?>">
            <input type="hidden" name="app_public_url" value="<?= htmlspecialchars($settings['app_public_url'] ?? '', ENT_QUOTES) ?>">
            <p class="form-hint"><?= __('public_url_forced_by_env') ?></p>
        <?php else: ?>
            <input type="url" id="app_public_url" name="app_public_url" class="form-control"
                placeholder="<?= htmlspecialchars((string) ($public_url_suggestion ?? 'https://kintai.example.com'), ENT_QUOTES) ?>"
                value="<?= htmlspecialchars($settings['app_public_url'] ?? '', ENT_QUOTES) ?>">
            <p class="form-hint"><?= __('public_url_hint') ?></p>
            <?php if (empty($settings['app_public_url']) && !empty($public_url_suggestion)): ?>
                <p class="form-hint"><?= htmlspecialchars(__('public_url_suggestion', ['url' => $public_url_suggestion])) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php
    echo Card::make()->header(__('identity'))->body(ob_get_clean())->render();
    ?>

    <?php
    ob_start();
    ?>

    <div class="theme-container">
        <div class="theme-foxy-palette">
            <img src="<?= $BASE_URL ?>/assets/img/mascot/kitsune/brand-icon.png" alt="<?= __('mascot_alt') ?>" class="theme-foxy-palette__icon">
            <div>
                <p class="theme-foxy-palette__title"><?= __('theme_foxy_palette_title') ?> <span class="badge badge--success badge--xs"><?= __('theme_color_recommended') ?></span></p>
                <!-- <p class="text-sm-muted"><?= __('theme_foxy_palette_hint') ?></p> -->
                <div class="color-presets">
                    <?php foreach ($foxyPalette as $swatch): ?>
                        <span class="color-preset theme-foxy-palette__swatch--<?= $swatch['css'] ?>" title="<?= __($swatch['label']) ?> — <?= $swatch['color'] ?>"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="theme-foxy-palette">
            <img src="<?= $BASE_URL ?>/assets/img/mascot/tanuki/brand-icon.png" alt="<?= __('mascot_alt') ?>" class="theme-foxy-palette__icon">
            <div>
                <p class="theme-foxy-palette__title"><?= __('theme_tanuki_palette_title') ?> <span class="badge badge--success badge--xs"><?= __('theme_color_recommended') ?></span></p>
                <div class="color-presets">
                    <?php foreach ($tanukiPalette as $swatch): ?>
                        <span class="color-preset theme-tanuki-palette__swatch--<?= $swatch['css'] ?>" title="<?= __($swatch['label']) ?> — <?= $swatch['color'] ?>"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label" for="app_mascot_mode"><?= __('mascot_mode_label') ?></label>
        <select id="app_mascot_mode" name="app_mascot_mode" class="form-control">
            <option value="mix" <?= ($settings['app_mascot_mode'] ?? 'mix') === 'mix' ? 'selected' : '' ?>><?= __('mascot_mode_mix') ?></option>
            <option value="kitsune" <?= ($settings['app_mascot_mode'] ?? '') === 'kitsune' ? 'selected' : '' ?>><?= __('mascot_mode_kitsune') ?></option>
            <option value="tanuki" <?= ($settings['app_mascot_mode'] ?? '') === 'tanuki' ? 'selected' : '' ?>><?= __('mascot_mode_tanuki') ?></option>
        </select>
        <p class="form-hint"><?= __('mascot_mode_hint') ?></p>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('theme_dark_mode') ?></label>
        <div class="btn-group btn-group--switcher btn-group--switcher-2 mb-xs" data-theme-dark-mode-switcher>
            <span class="btn-group__thumb btn-group__thumb--pos-<?= $theme_dark_mode === 'manual' ? 1 : 0 ?>" aria-hidden="true"></span>
            <button type="button" class="btn btn--ghost btn--sm <?= $theme_dark_mode !== 'manual' ? 'btn--active' : '' ?>" data-dark-mode-option="auto"><?= __('theme_dark_mode_auto') ?></button>
            <button type="button" class="btn btn--ghost btn--sm <?= $theme_dark_mode === 'manual' ? 'btn--active' : '' ?>" data-dark-mode-option="manual"><?= __('theme_dark_mode_manual') ?></button>
        </div>
        <input type="hidden" name="app_theme_dark_mode" id="app_theme_dark_mode" value="<?= htmlspecialchars($theme_dark_mode, ENT_QUOTES) ?>">
        <p class="form-hint"><?= __('theme_dark_mode_hint') ?></p>
    </div>

    <div class="theme-color-grid">
        <?php foreach ($themeGroups as $group => $meta): ?>
            <div class="theme-color-card">
                <label class="form-label" for="app_<?= $group ?>_color"><?= __($meta['label']) ?></label>
                <input type="color" id="app_<?= $group ?>_color" name="app_<?= $group ?>_color" class="input-color"
                    value="<?= htmlspecialchars($theme_colors[$group] ?? '', ENT_QUOTES) ?>">
                <p class="text-sm-muted theme-color-card__hint"><?= __($meta['hint']) ?></p>
                <div class="theme-dark-color" data-theme-dark-color-for="<?= $group ?>" <?= $theme_dark_mode !== 'manual' ? 'hidden' : '' ?>>
                    <label class="form-label" for="app_<?= $group ?>_color_dark"><?= __('theme_dark_color_label') ?></label>
                    <input type="color" id="app_<?= $group ?>_color_dark" name="app_<?= $group ?>_color_dark" class="input-color"
                        value="<?= htmlspecialchars($theme_colors_dark[$group], ENT_QUOTES) ?>">
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    echo Card::make()->header(__('appearance'))->body(ob_get_clean())->render();
    ?>

    <?php
    ob_start();
    ?>

    <div class="form-group">
        <label class="form-label"><?= __('login_notice') ?></label>
        <textarea name="app_login_notice" class="form-control" rows="3"
            maxlength="300"
            placeholder="<?= __('login_notice_placeholder') ?>"><?= htmlspecialchars($settings['app_login_notice'] ?? '', ENT_QUOTES) ?></textarea>
        <p class="form-hint"><?= __('login_notice_hint') ?></p>
    </div>

    <?php
    echo Card::make()->header(__('login_page'))->body(ob_get_clean())->render();
    ?>

    <?php
    ob_start();
    ?>

    <div class="form-group">
        <label class="form-label"><?= __('maintenance_mode_enabled') ?></label>
        <label class="form-toggle">
            <input type="hidden" name="maintenance_mode_enabled" value="0">
            <input type="checkbox" name="maintenance_mode_enabled" value="1" class="form-toggle__input"
                <?= ($settings['maintenance_mode_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span class="form-toggle__track"></span>
        </label>
        <p class="form-hint"><?= __('maintenance_mode_hint') ?></p>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('maintenance_message') ?></label>
        <textarea name="maintenance_message" class="form-control" rows="3"
            maxlength="500"
            placeholder="<?= __('maintenance_message_placeholder') ?>"><?= htmlspecialchars($settings['maintenance_message'] ?? '', ENT_QUOTES) ?></textarea>
        <p class="form-hint"><?= __('maintenance_message_hint') ?></p>
    </div>

    <?php
    echo Card::make()->header(__('maintenance_mode'))->body(ob_get_clean())->render();
    ?>

    <?php
    ob_start();
    ?>

    <div class="form-group">
        <label class="form-label"><?= __('access_log_enabled') ?></label>
        <label class="form-toggle">
            <input type="hidden" name="access_log_enabled" value="0">
            <input type="checkbox" name="access_log_enabled" value="1" class="form-toggle__input"
                <?= ($settings['access_log_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span class="form-toggle__track"></span>
        </label>
        <p class="form-hint"><?= __('access_log_enabled_hint') ?></p>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('log_retention_days') ?></label>
        <input type="number" name="log_retention_days" class="form-control w-100" min="0" max="3650"
            value="<?= (int) ($settings['log_retention_days'] ?? 180) ?>">
        <p class="form-hint"><?= __('log_retention_days_hint') ?></p>
    </div>

    <?php
    echo Card::make()->header(__('activity_log'))->body(ob_get_clean())->render();
    ?>

    <?php
    ob_start();
    ?>

    <div class="form-group">
        <label class="form-label" for="api_rate_limit_per_minute"><?= __('api_rate_limit_per_minute') ?></label>
        <input type="number" id="api_rate_limit_per_minute" name="api_rate_limit_per_minute" class="form-control w-100" min="0" max="100000"
            value="<?= (int) ($settings['api_rate_limit_per_minute'] ?? 300) ?>">
        <p class="form-hint"><?= __('api_rate_limit_per_minute_hint') ?></p>
    </div>

    <?php
    echo Card::make()->header(__('api_settings'))->body(ob_get_clean())->render();
    ?>

    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
        <a href="<?= route_url('home') ?>" class="btn btn--ghost"><?= __('cancel') ?></a>
    </div>
</form>

<script src="<?= $BASE_URL ?>/assets/js/modules/owner-settings.js"></script>