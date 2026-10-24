<?php
// Barre de navigation mobile, construite à partir de données (kintai\Core\Extension\Navigation) : les entrées
// marquées « mobile » du Core et des bundles actifs (Bundle::navigation(), contrat 1.1), plus « Mon profil » côté
// employé. L'utilisateur en choisit 2 à 4 dans ses préférences ; à défaut, celles par défaut de son rôle. Une entrée
// n'apparaît que si la permission de sa route est accordée (route_visible, RBAC-V2) et la fonctionnalité du magasin
// active ; plus de liste recopiée à la main ici.
$routeVisible = $route_visible ?? fn(string $routeName): bool => true;
$_bnHomeHref   = route_url($isManager ? 'home' : 'employee.dashboard');
$_bnHomeActive = $isManager ? ($path === '/' || $path === '') : ($path === '/employee' || $path === '/');
$_bnTabs = \kintai\Core\Extension\Navigation::current()->mobileTabs(
    (array)($user_bottom_nav_items ?? []),
    [
        'isManager'    => $isManager,
        'path'         => $path,
        'routeVisible' => $routeVisible,
        'feat'         => $feat,
        'routeUrl'     => fn(string $r): string => route_url($r),
        'translate'    => fn(string $k): string => __($k),
        'view'         => get_defined_vars(),
    ]
);
?>
<nav class="bottom-nav" aria-label="Navigation principale">
    <a href="<?= $_bnHomeHref ?>" class="nav-tab<?= $_bnHomeActive ? ' nav-tab--active' : '' ?>">
        <span class="nav-tab__icon"><?= $svgIcon('home') ?></span>
        <span class="nav-tab__label"><?= __('home') ?></span>
    </a>
    <?php foreach ($_bnTabs as $_bnTab): ?>
    <a href="<?= htmlspecialchars($_bnTab['href']) ?>" class="nav-tab<?= $_bnTab['active'] ? ' nav-tab--active' : '' ?>">
        <span class="nav-tab__icon"><?= $svgIcon($_bnTab['icon']) ?></span>
        <span class="nav-tab__label"><?= htmlspecialchars($_bnTab['label']) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
