<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\Requests;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Extension\PendingRequests;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\Controller\Web\HasAdminAccess;
use kintai\UI\ViewRenderer;

/**
 * Page de résumé "Demandes" : agrège les demandes en attente de toutes sortes (congés, échanges de shifts,
 * candidatures bourse aux shifts…) sur une seule page, avec des liens vers les pages dédiées de chaque bundle pour le
 * traitement (approuver/refuser). Ne duplique aucune logique métier : chaque sorte de demande vient d'un fournisseur
 * (kintai\Core\Extension\PendingRequests — Bundle::pendingRequestsProviders(), contrat 1.9, ou repli historique).
 */
final class AdminRequestsController
{
    use HasAdminAccess;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly PermissionService $permissions,
        private readonly ?PendingRequests $pendingRequests = null,
    ) {}

    public function index(Request $request): Response
    {
        $user       = $request->getAttribute('auth_user');
        $managedIds = $this->managedIds($request);

        $isOwner = !empty($user['is_admin']);
        $can     = fn(string $key): bool => $isOwner || $this->permissions->can($user, $key, null);

        // Une section par sorte de demande que l'utilisateur peut voir et dont le bundle est actif (chaque fournisseur
        // est isolé : un bundle défaillant ne fait pas tomber la page).
        $sections = ($this->pendingRequests ?? PendingRequests::current())
            ->sections($managedIds, $can, static fn(?string $feature): bool => feat_bundle($feature));

        return Response::html($this->view->render('requests.index', [
            'title'      => __('requests'),
            'sections'   => $sections,
            'users_map'  => $this->buildUsersMap(),
            'stores_map' => $this->buildStoresMap($managedIds),
        ], 'layout.app'));
    }
}
