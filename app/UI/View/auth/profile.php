<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * @var array  $user
 * @var string $tab               'info'|'availability'|'ical'|'notifications'|'nav'|'data'
 * @var array  $notif_grid        catégorie → [['type' => NotificationType, 'push' => bool, 'email' => bool]] (onglet notifications)
 * @var array|null $quiet_hours   ['enabled' => bool, 'start' => 'HH:MM', 'end' => 'HH:MM']
 * @var bool   $email_available   L'envoi d'e-mail est réglé sur l'instance
 * @var array  $stores            id → store
 * @var int    $store_id
 * @var array  $existing          day_of_week → availability row
 * @var array  $ical_links
 * @var array  $nav_hidden
 * @var array  $nav_section_order
 * @var array  $nav_default_sections
 * @var bool   $is_owner
 * @var array  $bottom_nav_items
 */

$dayNames = [
    1 => __('monday'),    2 => __('tuesday'),   3 => __('wednesday'),
    4 => __('thursday'),  5 => __('friday'),     6 => __('saturday'),
    7 => __('sunday'),
];
$defaultOpen  = '09:00';
$defaultClose = '18:00';
?>

<div class="page-header">
    <h2 class="page-header__title"><?= __('profile') ?></h2>
</div>

<?php
echo Flash::fromQuery('success', [
    'saved'            => __('save_success'),
    'password_changed' => __('password_changed_success'),
    'avatar_saved'     => __('avatar_saved'),
    'avatar_removed'   => __('avatar_removed'),
    '1'                => __('operation_success'),
])->render();
$errMsg = match ($_GET['error'] ?? '') {
    'password_mismatch'      => __('password_mismatch'),
    'password_too_short'     => __('password_too_short'),
    'current_password_wrong' => __('current_password_wrong'),
    'owner_cannot_delete'    => __('error_owner_cannot_remove_self'),
    'avatar_invalid'         => __('avatar_invalid'),
    'quiet_hours_invalid'    => __('notif_quiet_invalid'),
    default                  => '',
};
if ($errMsg !== '') {
    echo Alert::make($errMsg)->danger()->render();
}
?>

<!-- Onglets -->
<div class="profile-tabs card--mb">
    <a href="<?= route_url('profile') ?>?tab=info"
       class="profile-tab<?= $tab === 'info' ? ' profile-tab--active' : '' ?>">
        <?= __('profile_tab_info') ?>
    </a>
    <a href="<?= route_url('profile') ?>?tab=availability"
       class="profile-tab<?= $tab === 'availability' ? ' profile-tab--active' : '' ?>">
        <?= __('profile_tab_availability') ?>
    </a>
    <a href="<?= route_url('profile') ?>?tab=ical"
       class="profile-tab<?= $tab === 'ical' ? ' profile-tab--active' : '' ?>">
        <?= __('profile_tab_ical') ?>
    </a>
    <a href="<?= route_url('profile') ?>?tab=notifications"
       class="profile-tab<?= $tab === 'notifications' ? ' profile-tab--active' : '' ?>">
        <?= __('profile_tab_push') ?>
    </a>
    <a href="<?= route_url('profile') ?>?tab=nav"
       class="profile-tab<?= $tab === 'nav' ? ' profile-tab--active' : '' ?>">
        <?= __('profile_tab_nav') ?>
    </a>
    <a href="<?= route_url('profile') ?>?tab=data"
       class="profile-tab<?= $tab === 'data' ? ' profile-tab--active' : '' ?>">
        <?= __('tab_privacy') ?>
    </a>
</div>

<!-- ═══ TAB : INFORMATIONS ═══ -->
<?php if ($tab === 'info'): ?>
<?php
ob_start();
?>
<form method="POST" action="<?= route_url('profile') ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __('first_name') ?></label>
            <input type="text" class="form-control form-control--readonly"
                   value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" disabled>
        </div>
        <div class="form-group">
            <label class="form-label"><?= __('last_name') ?></label>
            <input type="text" class="form-control form-control--readonly"
                   value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" disabled>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('email') ?></label>
        <input type="email" class="form-control form-control--readonly"
               value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
    </div>

    <h4 class="section-title"><?= __('contact') ?></h4>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __('phone') ?></label>
            <input type="tel" name="phone" class="form-control"
                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                   placeholder="+33 6 00 00 00 00">
        </div>
        <div class="form-group">
            <label class="form-label"><?= __('mobile_phone') ?></label>
            <input type="tel" name="mobile_phone" class="form-control"
                   value="<?= htmlspecialchars($user['mobile_phone'] ?? '') ?>"
                   placeholder="090-XXXX-XXXX">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __('postal_code') ?></label>
            <input type="text" name="postal_code" class="form-control"
                   value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>"
                   placeholder="123-4567">
        </div>
        <div class="form-group">
            <label class="form-label"><?= __('address') ?></label>
            <input type="text" name="address" class="form-control"
                   value="<?= htmlspecialchars($user['address'] ?? '') ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('language') ?></label>
        <select name="language" class="form-control">
            <option value="" <?= ($user['language'] ?? '') === '' ? 'selected' : '' ?>><?= htmlspecialchars(__('language_follow_instance', ['language' => (string) ($instance_language_name ?? '')])) ?></option>
            <?php foreach (($active_languages ?? []) as $_lang): ?>
            <option value="<?= htmlspecialchars($_lang['code']) ?>" <?= ($user['language'] ?? '') === $_lang['code'] ? 'selected' : '' ?>><?= htmlspecialchars($_lang['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <h4 class="section-title"><?= __('profile_enriched') ?></h4>
    <div class="form-group">
        <label class="form-label"><?= __('bio') ?></label>
        <textarea name="bio" class="form-control" rows="3" maxlength="1000"
                  placeholder="<?= htmlspecialchars(__('bio_placeholder')) ?>"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __('skills') ?></label>
            <input type="text" name="skills" class="form-control"
                   value="<?= htmlspecialchars($user['skills'] ?? '') ?>"
                   placeholder="<?= htmlspecialchars(__('skills_placeholder')) ?>">
        </div>
        <div class="form-group">
            <label class="form-label"><?= __('languages_spoken') ?></label>
            <input type="text" name="languages_spoken" class="form-control"
                   value="<?= htmlspecialchars($user['languages_spoken'] ?? '') ?>"
                   placeholder="<?= htmlspecialchars(__('languages_spoken_placeholder')) ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __('hobbies') ?></label>
        <input type="text" name="hobbies" class="form-control"
               value="<?= htmlspecialchars($user['hobbies'] ?? '') ?>"
               placeholder="<?= htmlspecialchars(__('hobbies_placeholder')) ?>">
    </div>

    <?php foreach ($profile_sections ?? [] as $_sectionHtml): ?>
    <?= $_sectionHtml ?>
    <?php endforeach; ?>

    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
    </div>
</form>
<?php $infoBody = ob_get_clean();
echo Card::make(__('profile_tab_info'))->body($infoBody)->render();

ob_start();
$_avatarPath = $user['avatar_path'] ?? null;
$_avatarInitials = htmlspecialchars(strtoupper(
    mb_substr((string) ($user['last_name'] ?? ''), 0, 1) . mb_substr((string) ($user['first_name'] ?? ''), 0, 1)
) ?: '··');
?>
<div class="profile-avatar-row">
    <?php if ($_avatarPath): ?>
        <img src="<?= route_url('user.avatar', ['user_id' => $user['id']]) ?>" alt="" class="profile-avatar-preview">
    <?php else: ?>
        <span class="avatar-chip profile-avatar-preview profile-avatar-preview--fallback <?= style_class(['--chip-bg' => $user['color'] ?? '#3B82F6']) ?>"><?= $_avatarInitials ?></span>
    <?php endif; ?>
    <div class="profile-avatar-actions">
        <form method="POST" action="<?= route_url('profile.avatar') ?>" enctype="multipart/form-data" class="form-inline-flex">
            <?= csrf_field() ?>
            <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <?= Button::make(__('avatar_change'))->ghost()->sm()->submit()->render() ?>
        </form>
        <?php if ($_avatarPath): ?>
        <form method="POST" action="<?= route_url('profile.avatar.delete') ?>"
              data-confirm="<?= htmlspecialchars(__('avatar_remove_confirm'), ENT_QUOTES) ?>">
            <?= csrf_field() ?>
            <?= Button::make(__('avatar_remove'))->danger()->sm()->submit()->render() ?>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php
echo Card::make()->header(__('avatar'))->body(ob_get_clean())->render();

ob_start();
?>
<?php if (empty($user['email'])):
    echo Alert::make(__('profile_no_email_warning'))->warning()->render();
endif; ?>
<?php if (!empty($has_default_password)):
    echo Alert::make(__('profile_must_change_password_warning'))->warning()->render();
endif; ?>
<form method="POST" action="<?= route_url('profile.password') ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label form-label--required"><?= __('current_password') ?></label>
        <input type="password" name="current_password" class="form-control" required>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label form-label--required"><?= __('new_password') ?></label>
            <input type="password" name="new_password" class="form-control" minlength="<?= \kintai\Core\Auth\PasswordPolicy::MIN_LENGTH ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label form-label--required"><?= __('confirm_password') ?></label>
            <input type="password" name="confirm_password" class="form-control" minlength="<?= \kintai\Core\Auth\PasswordPolicy::MIN_LENGTH ?>" required>
        </div>
    </div>
    <div class="form-actions">
        <?= Button::make(__('change_password'))->primary()->submit()->render() ?>
    </div>
</form>
<?php $pwdBody = ob_get_clean();
echo Card::make(__('change_password'))->body($pwdBody)->render();
?>

<!-- ═══ TAB : DISPONIBILITÉS ═══ -->
<?php elseif ($tab === 'availability'): ?>

<?php if (empty($stores)):
    echo Alert::make(__('no_store_assigned'))->info()->render();
else: ?>

<?php if (count($stores) > 1): ?>
<div class="card card--mb">
    <div class="card-body">
        <form method="GET" action="<?= route_url('profile') ?>" class="form-inline-flex">
            <input type="hidden" name="tab" value="availability">
            <label class="form-label" for="store_sel"><?= __('store') ?></label>
            <select id="store_sel" name="store_id" class="form-control" data-submit-on-change>
                <?php foreach ($stores as $sid => $s): ?>
                    <option value="<?= (int) $sid ?>" <?= $sid === $store_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name'] ?? '#' . $sid) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>
<?php endif; ?>

<p class="text-muted mb-sm"><?= __('availability_subtitle') ?></p>

<form method="POST" action="<?= route_url('employee.profile.availability') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="store_id" value="<?= (int) $store_id ?>">
    <div class="card card--mb">
        <div class="table-wrap">
            <table class="data-table avail-table">
                <thead>
                    <tr>
                        <th><?= __('day') ?></th>
                        <th><?= __('available') ?></th>
                        <th><?= __('start') ?></th>
                        <th><?= __('end') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dayNames as $dow => $label): ?>
                        <?php
                        $row     = $existing[$dow] ?? null;
                        $isAvail = $row === null || (int) ($row['is_available'] ?? 1) === 1;
                        $start   = ($isAvail && $row) ? substr($row['start_time'], 0, 5) : $defaultOpen;
                        $end     = ($isAvail && $row) ? substr($row['end_time'],   0, 5) : $defaultClose;
                        ?>
                        <tr id="avail-row-<?= $dow ?>"<?= !$isAvail ? ' class="avail-row--off"' : '' ?>>
                            <td><?= htmlspecialchars($label) ?></td>
                            <td>
                                <label class="form-toggle">
                                    <input type="checkbox" name="days[<?= $dow ?>][available]" value="1"
                                           class="form-toggle__input" data-dow="<?= $dow ?>"
                                           <?= $isAvail ? 'checked' : '' ?>>
                                    <span class="form-toggle__track"></span>
                                </label>
                            </td>
                            <td>
                                <input type="time" name="days[<?= $dow ?>][start_time]"
                                       value="<?= htmlspecialchars($start) ?>"
                                       class="form-control" id="start-<?= $dow ?>"
                                       <?= !$isAvail ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <input type="time" name="days[<?= $dow ?>][end_time]"
                                       value="<?= htmlspecialchars($end) ?>"
                                       class="form-control" id="end-<?= $dow ?>"
                                       <?= !$isAvail ? 'disabled' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
    </div>
</form>

<script src="<?= $BASE_URL ?>/assets/js/modules/profile.js"></script>

<?php endif; ?>

<!-- ═══ TAB : MENU ═══ -->
<?php elseif ($tab === 'nav'): ?>

<?php
// Défaut à false (pas à true) : AuthController passe toujours cette valeur explicitement
// (depuis AuthService::isManager(), déjà corrigé — voir CHANGELOG), gardé par cohérence
// défensive avec le même principe appliqué à shifts-timeline.php/shifts-calendar.php.
$_isManagerView = $is_manager_view ?? false;

$_navSaveAction = route_url($_isManagerView ? 'admin.nav_settings' : 'employee.nav_settings');

$_navHasFeat = fn(?string $f): bool =>
    $f === null || ($is_owner ?? false) || $store_features === null || in_array($f, (array) $store_features, true);

// Entrées masquables : la navigation réelle du rôle (Core + bundles actifs, voir kintai\Core\Extension\Navigation),
// avec les mêmes clés que la barre du haut — l'ancienne liste employé (my_planning, my_timeoff…) n'y
// correspondait pas, si bien que masquer une entrée depuis le profil n'avait aucun effet.
$_navigation = \kintai\Core\Extension\Navigation::current();
$_navByKey = $_navigation->hideableKeys($_isManagerView, $route_visible ?? fn(string $r): bool => true, $_navHasFeat, get_defined_vars());
$_navSections = [];
foreach ($nav_section_order ?? array_keys($_navByKey) as $_sk) {
    if (!empty($_navByKey[$_sk])) {
        $_navSections[$_sk] = $_navByKey[$_sk];
    }
}

// Barre mobile : entrées « mobile » de la navigation réelle, permises et actives pour ce magasin.
$_filteredBnPool = $_navigation->mobileChoices($_isManagerView, $route_visible ?? fn(string $r): bool => true, $_navHasFeat);
?>

<form method="POST" action="<?= $_navSaveAction ?>">
    <?= csrf_field() ?>

    <?php
    ob_start();
    ?>
    <div class="form-inline-flex mb-sm">
        <p class="text-muted flex-1"><?= __('nav_section_order_desc') ?></p>
        <?= Button::make(__('nav_reset_order'))
            ->ghost()->sm()
            ->attrs(['name' => 'reset_section_order', 'value' => '1',
                'data-confirm' => __('nav_reset_order') . ' ?'])
            ->submit()->render() ?>
    </div>
    <ul class="nav-order-list" id="navSectionOrder">
        <?php foreach ($nav_section_order as $_sk): if (!isset($_navSections[$_sk])) continue; ?>
            <li class="nav-order-item" draggable="true" data-key="<?= htmlspecialchars($_sk) ?>">
                <span class="nav-order-handle" aria-hidden="true">⠿</span>
                <span class="nav-order-label"><?= __($_sk) ?></span>
                <div class="nav-order-arrows">
                    <button type="button" class="nav-order-btn nav-order-btn--up" aria-label="<?= __('nav_move_up') ?>">▲</button>
                    <button type="button" class="nav-order-btn nav-order-btn--down" aria-label="<?= __('nav_move_down') ?>">▼</button>
                </div>
                <input type="hidden" name="section_order[]" value="<?= htmlspecialchars($_sk) ?>">
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    echo Card::make(__('nav_section_order'))->body(ob_get_clean())->render();
    ob_start();
    ?>
    <p class="text-muted mb-sm"><?= __('nav_settings_desc') ?></p>

    <div class="form-group mb-sm">
        <div class="nav-pref-item nav-pref-item--fixed">
            <label class="form-toggle">
                <input type="checkbox" class="form-toggle__input" checked disabled>
                <span class="form-toggle__track"></span>
            </label>
            <span class="nav-pref-label"><?= __('dashboard') ?></span>
            <?= Badge::make(__('nav_always_visible'))->muted()->sm()->render() ?>
        </div>
    </div>

    <?php foreach ($nav_section_order as $_sk): if (!isset($_navSections[$_sk])) continue; ?>
        <div class="nav-pref-section">
            <div class="nav-pref-section-title"><?= __($_sk) ?></div>
            <?php foreach ($_navSections[$_sk] as $_k): ?>
                <div class="nav-pref-item">
                    <label class="form-toggle">
                        <input type="checkbox"
                               name="nav[<?= $_k ?>]"
                               value="1"
                               class="form-toggle__input"
                               <?= !in_array($_k, $nav_hidden, true) ? 'checked' : '' ?>>
                        <span class="form-toggle__track"></span>
                    </label>
                    <span class="nav-pref-label"><?= __($_navigation->labelOf($_k, $_isManagerView)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php
    echo Card::make(__('nav_items_visibility'))->body(ob_get_clean())->render();
    ?>

    <?php if (!empty($_filteredBnPool)):
    ob_start();
    ?>
    <p class="text-muted mb-sm"><?= __('bottom_nav_config_desc') ?></p>
    <p class="text-muted mb-sm text--sm hidden" id="bottomNavHint"><?= __('bottom_nav_min_max') ?></p>

    <div class="form-group mb-sm">
        <div class="nav-pref-item nav-pref-item--fixed">
            <label class="form-toggle">
                <input type="checkbox" class="form-toggle__input" checked disabled>
                <span class="form-toggle__track"></span>
            </label>
            <span class="nav-pref-label"><?= __('home') ?></span>
            <?= Badge::make(__('nav_always_visible'))->muted()->sm()->render() ?>
        </div>
    </div>

    <?php foreach ($_filteredBnPool as $_bnKey): ?>
        <div class="nav-pref-item">
            <label class="form-toggle">
                <input type="checkbox"
                       name="bottom_nav[]"
                       value="<?= htmlspecialchars($_bnKey) ?>"
                       class="form-toggle__input bottom-nav-check"
                       <?= in_array($_bnKey, $bottom_nav_items ?? [], true) ? 'checked' : '' ?>>
                <span class="form-toggle__track"></span>
            </label>
            <span class="nav-pref-label"><?= __($_navigation->labelOf($_bnKey, $_isManagerView)) ?></span>
        </div>
    <?php endforeach; ?>
    <?php
    echo Card::make(__('bottom_nav_config'))->body(ob_get_clean())->render();
    endif; ?>

    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
    </div>
</form>
<script src="<?= $BASE_URL ?>/assets/js/modules/nav-order.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/bottom-nav-config.js"></script>

<!-- ═══ TAB : CALENDRIER iCal ═══ -->
<?php elseif ($tab === 'ical'): ?>

<?php
// Liaison Google Calendar : mise à jour automatique, contrairement à l'abonnement iCal que Google relit quand il veut.
if (isset($calendar)) {
    include __DIR__ . '/_profile-google-calendar.php';
}
ob_start();
?>
<p class="text-muted mb-sm"><?= __('ical_feeds_description') ?></p>
<div class="alert alert--info mb-sm"><?= __('ical_google_delay_notice') ?></div>
<?php if (empty($ical_links)): ?>
    <p class="text-muted"><?= __('no_store_assigned') ?></p>
<?php else: ?>
    <?php foreach ($ical_links as $link): ?>
    <div class="form-group mb-sm">
        <label class="form-label"><?= htmlspecialchars($link['store_name']) ?></label>
        <div class="form-inline-flex">
            <input type="text" class="form-control" readonly
                   value="<?= htmlspecialchars($link['url']) ?>"
                   data-on-click="@select">
            <button type="button" class="btn btn--ghost btn--sm"
                    data-copy-url="<?= htmlspecialchars($link['url']) ?>"
                    data-on-click="@copy">
                <?= __('ical_copy') ?>
            </button>
            <?php if (!empty($link['add'])): ?>
            <a class="btn btn--primary btn--sm" href="<?= htmlspecialchars($link['add']['google']) ?>" target="_blank" rel="noopener"><?= __('ical_add_google') ?></a>
            <a class="btn btn--ghost btn--sm" href="<?= htmlspecialchars($link['add']['outlook']) ?>" target="_blank" rel="noopener"><?= __('ical_add_outlook') ?></a>
            <a class="btn btn--ghost btn--sm" href="<?= htmlspecialchars($link['add']['webcal']) ?>"><?= __('ical_add_apple') ?></a>
            <?php endif; ?>
            <form method="POST"
                  action="<?= $BASE_URL ?>/ical/<?= (int) $link['store_id'] ?>/regenerate"
                  data-confirm="<?= htmlspecialchars(__('ical_regenerate_confirm'), ENT_QUOTES) ?>">
                <?= csrf_field() ?>
                <?= Button::make(__('ical_regenerate'))->ghost()->sm()->submit()->render() ?>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php
echo Card::make(__('ical_feeds'))->body(ob_get_clean())->render();

// Téléchargement ponctuel de son horaire (JSON, CSV, ICS) : un formulaire GET, les trois boutons portent le format.
if (!empty($stores)) {
    ob_start();
    ?>
    <p class="text-muted mb-sm"><?= __('schedule_export_description') ?>
        <a href="<?= $BASE_URL ?>/exports"><?= __('export_title') ?> →</a></p>
    <form method="GET" action="<?= $BASE_URL ?>/profile/schedule-export" class="form-stack">
        <div class="form-row">
            <?php if (count($stores) > 1): ?>
            <div class="form-group">
                <label class="form-label" for="schedule-export-store"><?= __('store') ?></label>
                <select class="form-control" id="schedule-export-store" name="store_id">
                    <?php foreach ($stores as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) ($s['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="store_id" value="<?= (int) array_key_first($stores) ?>">
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label" for="schedule-export-from"><?= __('schedule_export_from') ?></label>
                <input type="date" class="form-control" id="schedule-export-from" name="from" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="schedule-export-to"><?= __('schedule_export_to') ?></label>
                <input type="date" class="form-control" id="schedule-export-to" name="to" value="<?= date('Y-m-d', strtotime('+90 days')) ?>">
            </div>
        </div>
        <div class="form-inline-flex">
            <button type="submit" name="format" value="ics" class="btn btn--primary btn--sm"><?= __('schedule_export_ics') ?></button>
            <button type="submit" name="format" value="csv" class="btn btn--ghost btn--sm"><?= __('schedule_export_csv') ?></button>
            <button type="submit" name="format" value="json" class="btn btn--ghost btn--sm"><?= __('schedule_export_json') ?></button>
        </div>
    </form>
    <?php
    echo Card::make(__('schedule_export_title'))->body(ob_get_clean())->render();
}
?>

<!-- ═══ TAB : NOTIFICATIONS ═══ -->
<?php elseif ($tab === 'notifications'): ?>

<?php
ob_start();
?>
<?php if (empty($push_web_config)): ?>
    <p class="text-muted"><?= __('push_not_configured') ?></p>
<?php else: ?>
    <p id="push-status-unsupported" class="text-muted" hidden><?= __('push_unsupported') ?></p>
    <p id="push-status-ios" class="text-muted" hidden><?= __('push_ios_install_required') ?></p>
    <p id="push-status-error" class="text-muted" hidden><?= __('push_activation_failed') ?></p>
    <p id="push-status-denied" class="text-muted" hidden><?= __('push_permission_denied') ?></p>
    <div id="push-status-disabled" hidden>
        <p class="text-muted mb-sm"><?= __('push_notifications_description') ?></p>
        <?= Button::make(__('push_enable'))->primary()->sm()->attrs(['id' => 'push-enable-btn'])->render() ?>
    </div>
    <div id="push-status-enabled" hidden>
        <p class="text-muted mb-sm"><?= __('push_status_enabled_description') ?></p>
        <?= Button::make(__('push_disable'))->ghost()->sm()->attrs(['id' => 'push-disable-btn'])->render() ?>
    </div>
<?php endif; ?>
<?php
echo Card::make(__('notif_card_device'))->body(ob_get_clean())->render();

// Formulaire unique et vide : les cases des deux cartes suivantes s'y rattachent par form= (pas de <form> imbriqué).
$_notifForm = 'notif-prefs-form';
?>
<form id="<?= $_notifForm ?>" method="POST" action="<?= route_url('profile.notifications') ?>">
    <?= csrf_field() ?>
</form>

<?php
ob_start();
?>
<p class="text-muted mb-sm"><?= __('notif_prefs_intro') ?></p>
<?php if (!($email_available ?? false)): ?>
    <p class="text-muted mb-sm"><?= __('notif_email_unavailable') ?></p>
<?php endif; ?>
<?php if (empty($notif_grid)): ?>
    <p class="text-muted"><?= __('notif_prefs_empty') ?></p>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table notif-prefs-table">
            <thead>
                <tr>
                    <th><?= __('notif_col_type') ?></th>
                    <th><?= __('notif_col_inapp') ?></th>
                    <th>
                        <?= __('notif_col_push') ?>
                        <button type="button" class="notif-prefs-table__all" data-on-click="@toggleChecks" data-target=".notif-check--push"><?= __('notif_toggle_all') ?></button>
                    </th>
                    <th>
                        <?= __('notif_col_email') ?>
                        <button type="button" class="notif-prefs-table__all" data-on-click="@toggleChecks" data-target=".notif-check--email"><?= __('notif_toggle_all') ?></button>
                    </th>
                </tr>
            </thead>
            <?php foreach ($notif_grid as $_cat => $_rows): ?>
                <tbody>
                    <tr class="notif-prefs-table__category">
                        <th colspan="4"><?= __('notif_category_' . $_cat) ?></th>
                    </tr>
                    <?php foreach ($_rows as $_row): $_t = $_row['type']; ?>
                        <tr>
                            <td><input type="hidden" form="<?= $_notifForm ?>" name="shown[]" value="<?= htmlspecialchars($_t->type) ?>"><span aria-hidden="true"><?= htmlspecialchars($_t->icon) ?></span> <?= __('notif_' . $_t->type) ?></td>
                            <td>
                                <label class="form-toggle">
                                    <input type="checkbox" class="form-toggle__input" checked disabled>
                                    <span class="form-toggle__track"></span>
                                </label>
                            </td>
                            <?php foreach (['push', 'email'] as $_ch): ?>
                                <td>
                                    <label class="form-toggle">
                                        <input type="checkbox" form="<?= $_notifForm ?>" name="<?= $_ch ?>[]"
                                               value="<?= htmlspecialchars($_t->type) ?>"
                                               class="form-toggle__input notif-check--<?= $_ch ?>"
                                               <?= $_row[$_ch] ? 'checked' : '' ?>>
                                        <span class="form-toggle__track"></span>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>
<?php
echo Card::make(__('notif_card_prefs'))->body(ob_get_clean())->render();

ob_start();
$_qh = $quiet_hours ?? ['enabled' => false, 'start' => '22:00', 'end' => '07:00'];
?>
<p class="text-muted mb-sm"><?= __('notif_quiet_intro') ?></p>
<div class="form-group mb-sm">
    <label class="form-toggle">
        <input type="checkbox" form="<?= $_notifForm ?>" name="quiet_enabled" value="1"
               class="form-toggle__input" <?= $_qh['enabled'] ? 'checked' : '' ?>>
        <span class="form-toggle__track"></span>
    </label>
    <span><?= __('notif_quiet_enable') ?></span>
</div>
<div class="form-row">
    <div class="form-group">
        <label class="form-label" for="quiet-start"><?= __('notif_quiet_start') ?></label>
        <input type="time" id="quiet-start" form="<?= $_notifForm ?>" name="quiet_start"
               value="<?= htmlspecialchars($_qh['start']) ?>" class="form-control">
    </div>
    <div class="form-group">
        <label class="form-label" for="quiet-end"><?= __('notif_quiet_end') ?></label>
        <input type="time" id="quiet-end" form="<?= $_notifForm ?>" name="quiet_end"
               value="<?= htmlspecialchars($_qh['end']) ?>" class="form-control">
    </div>
</div>
<p class="text-muted"><?= __('notif_quiet_bell_note') ?></p>
<?php
echo Card::make(__('notif_card_quiet'))->body(ob_get_clean())->render();
?>
<div class="form-actions">
    <?= Button::make(__('save'))->primary()->submit()->attrs(['form' => $_notifForm])->render() ?>
</div>

<!-- ═══ TAB : CONFIDENTIALITÉ & DONNÉES ═══ -->
<?php elseif ($tab === 'data'): ?>

<?php
ob_start();
echo '<p class="mb-sm">' . __('gdpr_export_intro') . '</p>';
echo Button::make(__('gdpr_export_btn'))->primary()->link(route_url('profile.export'))->render();
echo Card::make(__('gdpr_export_title'))->body(ob_get_clean())->render();

ob_start();
echo Alert::make(__('gdpr_delete_warning'))->danger()->render();
?>
<form method="POST" action="<?= route_url('profile.delete') ?>" class="form-stack"
      data-confirm="<?= htmlspecialchars(__('gdpr_delete_confirm'), ENT_QUOTES) ?>">
    <?= csrf_field() ?>
    <div class="form-group mw-420">
        <label class="form-label form-label--required" for="delete_password">
            Confirmez avec votre mot de passe actuel
        </label>
        <input type="password" id="delete_password" name="password"
               class="form-control" required minlength="4">
    </div>
    <?= Button::make('Supprimer mon compte')
        ->danger()
        ->submit()->render() ?>
</form>
<?php
echo Card::make('Suppression du compte')->body(ob_get_clean())->render();

echo Card::make(__('privacy_policy_title'))
    ->body('<p>' . __('privacy_policy_intro') . '</p><a href="' . route_url('privacy') . '" class="btn btn--ghost">' . __('privacy_policy_link') . '</a>')
    ->render();
?>

<?php endif; ?>
