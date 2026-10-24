<?php
/**
 * Formulaire store — partiel inclus par stores-form.php
 * @var string $mode   'create'|'edit'
 * @var array  $store  Données du magasin
 * @var array|null $deductionSettings  Paramètres de cotisations
 * @var array|null $importSettings  Paramètres d'import Excel / pause auto (table store_import_settings)
 */
$mode ??= 'create';
$store ??= [];
$routeSlug ??= null; // ['slug' => …, 'is_manual' => bool] : alias d'URL courant (mode édition)
$deductionSettings ??= [];
$importSettings ??= [];

$excelDefaults = \kintai\Core\Services\ExcelShiftImport\ExcelShiftImportService::DEFAULTS;
$excelSettings = array_merge($excelDefaults, $importSettings);

$ded = $deductionSettings;
$dedEnabled     = !empty($ded['enabled']);
$dedHealth      = $ded['health_insurance_rate'] ?? 4.99;
$dedPension     = $ded['pension_rate'] ?? 9.15;
$dedEmployment  = $ded['employment_insurance_rate'] ?? 0.60;
$dedIncomeTax   = $ded['income_tax_rate'] ?? 0;
$dedResidentTax = $ded['resident_tax_monthly'] ?? 0;
?>
<div class="form-section-grid">
    <!-- ── Identité ──────────────────────────────────────── -->
    <div class="card card--c1 card--r1">
        <div class="card-header"><?= __('identity') ?></div>
        <div class="card-body form-stack">
            <div class="form-group">
                <label class="form-label form-label--required"><?= __('code') ?></label>
                <input type="text" name="code" class="form-control" maxlength="20"
                       value="<?= htmlspecialchars($store['code'] ?? '') ?>"
                       placeholder="ex: HQ, STORE01" required>
                <span class="form-hint"><?= __('code') ?> court unique, sera mis en majuscules.</span>
            </div>
            <div class="form-group">
                <label class="form-label form-label--required"><?= __('name') ?></label>
                <input type="text" name="name" class="form-control"
                       value="<?= htmlspecialchars($store['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('store_slug') ?></label>
                <input type="text" name="slug" class="form-control" maxlength="<?= \kintai\Core\Routing\SlugGenerator::MAX_LENGTH ?>"
                       pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="tokorozawa-higashicho"
                       value="<?= htmlspecialchars(!empty($routeSlug['is_manual']) ? (string) $routeSlug['slug'] : '') ?>">
                <span class="form-hint"><?= __('store_slug_hint') ?></span>
                <?php if (!empty($routeSlug['slug'])): ?>
                    <span class="form-hint"><?= __('store_slug_current') ?> <code>/admin/stores/<?= htmlspecialchars((string) $routeSlug['slug']) ?>/edit</code></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('type') ?></label>
                <input type="text" name="type" class="form-control"
                       value="<?= htmlspecialchars($store['type'] ?? 'retail') ?>"
                       placeholder="ex: retail, warehouse">
            </div>
            <?php if ($mode === 'edit'): ?>
            <div class="form-group">
                <label class="form-label"><?= __('status') ?></label>
                <select name="is_active" class="form-control">
                    <option value="1" <?= !empty($store['is_active']) ? 'selected' : '' ?>><?= __('active') ?></option>
                    <option value="0" <?= empty($store['is_active']) ? 'selected' : '' ?>><?= __('inactive') ?></option>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Localisation & devise ────────────────────────── -->
    <div class="card card--c2 card--r1">
        <div class="card-header"><?= __('locale') ?> & <?= __('currency') ?></div>
        <div class="card-body form-stack">
            <div class="form-group">
                <label class="form-label form-label--required"><?= __('timezone') ?></label>
                <?php // Liste des identifiants valides : un fuseau écrit à la main (« UTC+9 ») n'est pas compris par Google Agenda. ?>
                <input type="text" name="timezone" class="form-control" list="store-timezones" autocomplete="off"
                       value="<?= htmlspecialchars($store['timezone'] ?? date_default_timezone_get()) ?>"
                       placeholder="ex: Europe/Paris, Asia/Tokyo" required>
                <datalist id="store-timezones">
                    <?php foreach (\DateTimeZone::listIdentifiers() as $zone): ?>
                        <option value="<?= htmlspecialchars($zone) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('locale') ?></label>
                <input type="text" name="locale" class="form-control" maxlength="10"
                       value="<?= htmlspecialchars($store['locale'] ?? 'en') ?>"
                       placeholder="fr, en, ja">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('currency') ?></label>
                <?php $storeCurrency = strtoupper($store['currency'] ?? 'EUR'); ?>
                <select name="currency" class="form-control">
                    <?php foreach (\kintai\Core\Validation\StoreValidator::CURRENCIES as $curOpt): ?>
                        <option value="<?= $curOpt ?>"<?= $storeCurrency === $curOpt ? ' selected' : '' ?>><?= $curOpt ?> (<?= currency_symbol($curOpt) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="jpy-symbol-style-group">
                <label class="form-label"><?= __('currency_symbol_style') ?></label>
                <?php $symbolStyle = store_currency_style($store); ?>
                <select name="currency_symbol_style" class="form-control">
                    <option value="kanji"<?= $symbolStyle === 'kanji' ? ' selected' : '' ?>><?= __('currency_symbol_style_kanji') ?></option>
                    <option value="international"<?= $symbolStyle === 'international' ? ' selected' : '' ?>><?= __('currency_symbol_style_international') ?></option>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Contact ───────────────────────────────────────── -->
    <div class="card card--c3 card--r1 card--row-2">
        <div class="card-header"><?= __('contact') ?></div>
        <div class="card-body form-stack">
            <div class="form-group">
                <label class="form-label"><?= __('phone') ?></label>
                <input type="tel" name="phone" class="form-control"
                       value="<?= htmlspecialchars($store['phone'] ?? '') ?>"
                       placeholder="+33 1 00 00 00 00">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('email') ?></label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($store['email'] ?? '') ?>"
                       placeholder="contact@magasin.com">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('address') ?></label>
                <input type="text" name="address_street" class="form-control"
                       value="<?= htmlspecialchars($store['address_street'] ?? '') ?>"
                       placeholder="<?= __('street_placeholder') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('postal_code') ?></label>
                <input type="text" name="address_postal" class="form-control" maxlength="20"
                       value="<?= htmlspecialchars($store['address_postal'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('city') ?></label>
                <input type="text" name="address_city" class="form-control"
                       value="<?= htmlspecialchars($store['address_city'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('country') ?></label>
                <input type="text" name="address_country" class="form-control" maxlength="100"
                       value="<?= htmlspecialchars($store['address_country'] ?? '') ?>"
                       placeholder="France">
            </div>
        </div>
    </div>

    <!-- ── Cotisations sociales ─────────────────────────── -->
    <div class="card card--c1 card--r2">
        <div class="card-header"><?= __('deduction_settings') ?></div>
        <div class="card-body form-stack">
            <div class="form-group form-group--inline">
                <label class="form-check">
                    <input type="hidden" name="ded_enabled" value="0">
                    <input type="checkbox" name="ded_enabled" value="1" <?= $dedEnabled ? 'checked' : '' ?>>
                    <span><?= __('deductions_enabled') ?></span>
                </label>
            </div>
            <div id="ded-fields" <?= $dedEnabled ? '' : 'hidden' ?>>
                <div class="form-stack">
                    <div class="form-group">
                        <label class="form-label"><?= __('ded_health_insurance') ?> (%)</label>
                        <input type="number" name="ded_health_rate" class="form-control" step="0.01" min="0" max="100"
                               value="<?= htmlspecialchars((string) $dedHealth) ?>">
                        <span class="form-hint"><?= __('ded_rate_hint') ?></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('ded_pension') ?> (%)</label>
                        <input type="number" name="ded_pension_rate" class="form-control" step="0.01" min="0" max="100"
                               value="<?= htmlspecialchars((string) $dedPension) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('ded_employment_insurance') ?> (%)</label>
                        <input type="number" name="ded_employment_rate" class="form-control" step="0.01" min="0" max="100"
                               value="<?= htmlspecialchars((string) $dedEmployment) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('ded_income_tax') ?> (%)</label>
                        <input type="number" name="ded_income_tax_rate" class="form-control" step="0.01" min="0" max="100"
                               value="<?= htmlspecialchars((string) $dedIncomeTax) ?>">
                        <span class="form-hint"><?= __('ded_income_tax_hint') ?></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= __('ded_resident_tax') ?> (<?= __('monthly_fixed') ?>)</label>
                        <input type="number" name="ded_resident_tax" class="form-control" step="1" min="0"
                               value="<?= htmlspecialchars((string) $dedResidentTax) ?>">
                        <span class="form-hint"><?= __('ded_resident_tax_hint') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Paramètres de planning ───────────────────────── -->
    <div class="card card--c1 card--r3 card--col-3">
        <div class="card-header"><?= __('shift_settings') ?></div>
        <div class="card-body form-stack">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= __('week_start_day') ?></label>
                    <select name="week_start_day" class="form-control">
                        <?php
                        $wsdCurrent = (int) ($store['week_start_day'] ?? 1);
                        $wsdOptions = [1=>'monday',2=>'tuesday',3=>'wednesday',4=>'thursday',5=>'friday',6=>'saturday',7=>'sunday'];
                        foreach ($wsdOptions as $wsdVal => $wsdKey): ?>
                            <option value="<?= $wsdVal ?>"<?= $wsdCurrent === $wsdVal ? ' selected' : '' ?>><?= __($wsdKey) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint"><?= __('week_start_day_hint') ?></span>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('min_staff_per_day') ?></label>
                    <input type="number" name="min_staff_per_day" class="form-control" min="0"
                           value="<?= (int) ($store['min_staff_per_day'] ?? 0) ?>">
                    <span class="form-hint"><?= __('min_staff_per_day_hint') ?></span>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= __('min_shift_duration') ?></label>
                    <input type="number" name="min_shift_minutes" class="form-control" min="0"
                           value="<?= (int) ($store['min_shift_minutes'] ?? 120) ?>">
                    <span class="form-hint"><?= __('min_shift_minutes_hint') ?></span>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('max_shift_duration') ?></label>
                    <input type="number" name="max_shift_minutes" class="form-control" min="0"
                           value="<?= (int) ($store['max_shift_minutes'] ?? 480) ?>">
                    <span class="form-hint"><?= __('max_shift_minutes_hint') ?></span>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= __('auto_pause_threshold') ?></label>
                    <input type="number" name="auto_pause_after_minutes" class="form-control" min="0"
                           value="<?= (int) ($excelSettings['auto_pause_after_minutes'] ?? 0) ?>">
                    <span class="form-hint"><?= __('auto_pause_threshold_hint') ?></span>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('auto_pause_duration') ?></label>
                    <input type="number" name="auto_pause_minutes" class="form-control" min="1"
                           value="<?= (int) ($excelSettings['auto_pause_minutes'] ?? 30) ?>">
                    <span class="form-hint"><?= __('auto_pause_duration_hint') ?></span>
                </div>
            </div>
            <div class="form-group form-group--full">
                <label class="form-label"><?= __('low_staff_hours') ?></label>
                <div class="store-hour-row">
                    <div>
                        <span class="form-hint form-hint--label"><?= __('low_staff_hour_start') ?></span>
                        <input type="number" name="low_staff_hour_start" class="form-control store-hour-input" min="-1" max="23"
                               value="<?= (int) ($store['low_staff_hour_start'] ?? -1) ?>">
                    </div>
                    <span class="store-hour-sep">→</span>
                    <div>
                        <span class="form-hint form-hint--label"><?= __('low_staff_hour_end') ?></span>
                        <input type="number" name="low_staff_hour_end" class="form-control store-hour-input" min="-1" max="23"
                               value="<?= (int) ($store['low_staff_hour_end'] ?? -1) ?>">
                    </div>
                </div>
                <span class="form-hint"><?= __('low_staff_hours_hint') ?></span>
            </div>
        </div>
    </div>

    <!-- ── Import Excel ──────────────────────────────────── -->
    <div class="card card--c2 card--r2">
        <div class="card-header">
            <span><?= __('excel_import_settings') ?></span>
            <a href="<?= $BASE_URL ?>/assets/doc/template.xlsm" download
               class="btn btn--ghost btn--sm">↓ <?= __('excel_download_template') ?></a>
        </div>
        <div class="card-body form-stack">
            <div class="form-group">
                <label class="form-label"><?= __('excel_col_start') ?> (col_start)</label>
                <input type="number" name="excel_col_start" class="form-control" min="1"
                       value="<?= (int) $excelSettings['col_start'] ?>">
                <span class="form-hint"><?= __('excel_col_start_hint') ?></span>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('excel_col_end') ?> (col_end)</label>
                <input type="number" name="excel_col_end" class="form-control" min="1"
                       value="<?= (int) $excelSettings['col_end'] ?>">
                <span class="form-hint"><?= __('excel_col_end_hint') ?></span>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('excel_base_hour') ?> (base_hour)</label>
                <input type="number" name="excel_base_hour" class="form-control" min="0" max="23"
                       value="<?= (int) $excelSettings['base_hour'] ?>">
                <span class="form-hint"><?= __('excel_base_hour_hint') ?></span>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('excel_minutes_per_col') ?> (minutes_per_col)</label>
                <input type="number" name="excel_minutes_per_col" class="form-control" min="1"
                       value="<?= (int) $excelSettings['minutes_per_col'] ?>">
                <span class="form-hint"><?= __('excel_minutes_per_col_hint') ?></span>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('excel_block_size') ?> (block_size)</label>
                <input type="number" name="excel_block_size" class="form-control" min="2"
                       value="<?= (int) $excelSettings['block_size'] ?>">
                <span class="form-hint"><?= __('excel_block_size_hint') ?></span>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('excel_shift_rows') ?> (shift_rows)</label>
                <input type="number" name="excel_shift_rows" class="form-control" min="1"
                       value="<?= (int) $excelSettings['shift_rows'] ?>">
                <span class="form-hint"><?= __('excel_shift_rows_hint') ?></span>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('excel_sheet_filter_pattern') ?> (sheet_filter_pattern)</label>
                <input type="text" name="excel_sheet_filter_pattern" class="form-control"
                       value="<?= htmlspecialchars($excelSettings['sheet_filter_pattern']) ?>">
                <span class="form-hint"><?= __('excel_sheet_filter_pattern_hint') ?></span>
            </div>
        </div>
    </div>

    <!-- ── Fonctionnalités ───────────────────────────────── -->
    <div class="card card--c1 card--r4 card--full">
        <div class="card-header"><?= __('store_features') ?></div>
        <div class="card-body form-stack">
            <p class="form-hint"><?= __('store_features_hint') ?></p>
            <?php
            // Cartes fournies par le contrôleur : Core et bundles actifs (Bundle::storeFeatures(), contrat 1.3, ou
            // repli historique). Une fonctionnalité de bundle désactivé au niveau instance n'est pas proposée ici.
            $featureCards ??= [];
            $enabledFeatures ??= null;
            $isEnabled = fn(string $slug): bool => $enabledFeatures === null || in_array($slug, $enabledFeatures, true);
            ?>
            <div class="feature-cards">
                <?php foreach ($featureCards as $info): ?>
                    <?php $slug = $info['key']; ?>
                    <?php $on = $isEnabled($slug); ?>
                    <label class="feature-card">
                        <input type="hidden" name="feature_<?= $slug ?>" value="0">
                        <input type="checkbox" name="feature_<?= $slug ?>" value="1"
                               <?= $on ? 'checked' : '' ?> class="feature-card__input">
                        <div class="feature-card__icon"><?= $info['icon'] ?></div>
                        <div class="feature-card__body">
                            <span class="feature-card__title"><?= htmlspecialchars($info['label']) ?></span>
                            <span class="feature-card__desc"><?= htmlspecialchars($info['desc']) ?></span>
                        </div>
                        <div class="feature-card__toggle">
                            <span class="toggle-switch">
                                <span class="toggle-switch__track"></span>
                                <span class="toggle-switch__thumb"></span>
                            </span>
                            <span class="toggle-switch__label">
                                <?= $on ? __('active') : __('inactive') ?>
                            </span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
