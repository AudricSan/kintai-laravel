<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class AdminController
{
    use HasBaseUrl;
    // Entrées masquables : calculées par la vue depuis kintai\Core\Extension\Navigation (mêmes clés que la barre du haut).
    private const NAV_OWNER_SECTIONS   = ['planning', 'hr', 'requests', 'statistics', 'system'];
    private const NAV_MANAGER_SECTIONS = ['planning', 'hr', 'requests', 'statistics'];


    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserNavPrefsRepositoryInterface $navPrefs,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function navSettings(Request $request): Response
    {
        $user      = $request->getAttribute('auth_user');
        $isOwner   = !empty($user['is_admin']);
        $userId    = (int) $user['id'];

        $defaultSections = $isOwner ? self::NAV_OWNER_SECTIONS : self::NAV_MANAGER_SECTIONS;
        $hidden          = $this->navPrefs->getHidden($userId);
        $rawOrder        = $this->navPrefs->getSectionOrder($userId);
        $sectionOrder    = array_values(array_unique(array_merge(
            array_intersect($rawOrder, $defaultSections),
            $defaultSections
        )));

        // Barre mobile : choix calculés par la vue depuis la navigation réelle ; anciennes clés converties.
        $bottomNavSaved   = \kintai\Core\Extension\Navigation::migrateLegacyMobileKeys($this->navPrefs->getBottomNavItems($userId));
        $bottomNavItems   = !empty($bottomNavSaved) ? $bottomNavSaved : \kintai\Core\Extension\Navigation::MOBILE_DEFAULT_MANAGER;

        return Response::html($this->view->render('system.nav-settings', [
            'hidden'          => $hidden,
            'isOwner'         => $isOwner,
            'sectionOrder'    => $sectionOrder,
            'defaultSections' => $defaultSections,
            'bottomNavItems'  => $bottomNavItems,
            'title'           => __('nav_settings'),
        ], 'layout.app'));
    }

    public function saveNavSettings(Request $request): Response
    {
        $user      = $request->getAttribute('auth_user');
        $isOwner   = !empty($user['is_admin']);
        $userId    = (int) $user['id'];

        $defaultSections = $isOwner ? self::NAV_OWNER_SECTIONS : self::NAV_MANAGER_SECTIONS;

        $oldHidden = $this->navPrefs->getHidden($userId);
        $oldOrder  = $this->navPrefs->getSectionOrder($userId);
        $oldBottom = $this->navPrefs->getBottomNavItems($userId);

        // Seules les clés réellement proposées dans le formulaire (filtrées par store_features
        // côté vue) doivent voir leur état modifié ; les autres gardent leur préférence existante,
        // sinon une feature désactivée au moment de la sauvegarde serait masquée définitivement.
        $available   = array_values((array) $request->post('nav_available', []));
        $visible     = array_keys(array_filter((array) $request->post('nav', [])));
        $newlyHidden = array_diff($available, $visible);
        $keptHidden  = array_diff($oldHidden, $available);
        $hidden      = array_values(array_unique(array_merge($newlyHidden, $keptHidden)));
        $this->navPrefs->saveHidden($userId, $hidden);

        if ($request->post('reset_section_order')) {
            $this->navPrefs->saveSectionOrder($userId, []);
        } else {
            $rawOrder     = array_values((array) $request->post('section_order', []));
            $sectionOrder = array_values(array_intersect($rawOrder, $defaultSections));
            if (!empty($sectionOrder)) {
                $this->navPrefs->saveSectionOrder($userId, $sectionOrder);
            }
        }

        $pool         = \kintai\Core\Extension\Navigation::current()->mobileKeys(true);
        $rawBottom    = array_values((array) $request->post('bottom_nav', []));
        $bottomItems  = array_values(array_intersect($rawBottom, $pool));
        $count        = count($bottomItems);
        if ($count >= 2 && $count <= 4) {
            $this->navPrefs->saveBottomNavItems($userId, $bottomItems);
        }

        $this->auditLogger->logUpdate($request, 'nav_settings.updated', 'user_nav_prefs', $userId,
            ['hidden' => $oldHidden, 'section_order' => $oldOrder, 'bottom_nav' => $oldBottom],
            ['hidden' => $hidden, 'section_order' => $sectionOrder ?? [], 'bottom_nav' => $bottomItems],
            []
        );

        return Response::redirect($this->base() . '/admin/nav-settings?success=1');
    }

}
