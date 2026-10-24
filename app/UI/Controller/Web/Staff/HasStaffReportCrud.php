<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\Staff;

use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\PdfCjkFontResolver;

/**
 * CRUD générique pour les rapports RH par store (embauche, démission, salaire) :
 * ces trois types partageaient auparavant un seul AdminReportController avec un
 * dispatch `repo(string $type)`. Depuis leur extraction en bundles séparés,
 * chaque contrôleur ne gère plus qu'un seul type et fournit sa propre
 * config/repository via les méthodes abstraites ci-dessous ; ce trait porte
 * la logique jusqu'ici commune aux trois.
 *
 * Nécessite sur la classe utilisatrice : $this->view (ViewRenderer),
 * $this->stores (StoreRepositoryInterface), $this->auditLogger (AuditLogger),
 * $this->permissions (PermissionService, pour getManagersForReportForm()),
 * et le trait HasAdminAccess (base(), assertStoreAccess()).
 */
trait HasStaffReportCrud
{
    /** Le repository du rapport (findById/findByStore/findAll/save/delete). */
    abstract protected function reportRepo(): object;

    /**
     * @return array{entity: string, slug: string, view: string, not_found: string, fields: array<string, string>}
     */
    abstract protected function reportConfig(): array;

    abstract protected function reportShowTitle(array $report): string;

    abstract protected function reportEditTitle(): string;

    /** Données de formulaire propres au type (ex : liste des employés, des responsables). */
    abstract protected function reportEditExtras(int $storeId, array $report): array;

    /**
     * Résout le rapport depuis les paramètres de route ({id} store, {rid} rapport),
     * vérifie l'accès au store et l'appartenance du rapport.
     *
     * @return array{0: array, 1: int, 2: int} [report, storeId, reportId]
     */
    private function findReportOrFail(Request $request): array
    {
        $storeId  = (int) $request->param('id');
        $reportId = (int) $request->param('rid');
        $this->assertStoreAccess($request, $storeId);

        $report = $this->reportRepo()->findById($reportId);
        if ($report === null || (int) $report['store_id'] !== $storeId) {
            throw new NotFoundException(__($this->reportConfig()['not_found']));
        }

        return [$report, $storeId, $reportId];
    }

    /**
     * Construit les données d'un rapport depuis le POST selon le mapping de champs du type.
     */
    private function postData(Request $request, array $fields): array
    {
        $data = [];
        foreach ($fields as $field => $cast) {
            $data[$field] = match ($cast) {
                'str'   => $request->post($field, '') ?: null,
                'float' => (float) $request->post($field, 0),
                'int'   => (int) $request->post($field, 0),
                'null'  => null,
            };
        }
        return $data;
    }

    private function listReports(Request $request, string $titlePrefix): Response
    {
        $storeId = (int) $request->param('id');
        $store = $this->findStoreOrFail($storeId);
        $this->assertStoreAccess($request, $storeId);

        return Response::html($this->view->render($this->reportConfig()['view'], array_merge([
            'title'   => $titlePrefix . ' — ' . ($store['name'] ?? ''),
            'store'   => $store,
            'reports' => $this->reportRepo()->findByStore($storeId),
        ], $this->reportListExtras($storeId)), 'layout.app'));
    }

    /** Données additionnelles pour la vue liste, propres à un type de rapport (voir AdminSalaryReportController). */
    protected function reportListExtras(int $storeId): array
    {
        return [];
    }

    /**
     * Employés du store (pour un menu déroulant "Utilisateur"/employé du formulaire) —
     * inclut aussi $keepUserId même s'il n'est plus membre du store (édition
     * d'un ancien rapport dont l'employé a depuis été retiré du store).
     */
    private function storeMembersForReportForm(int $storeId, int $keepUserId = 0): array
    {
        $seenIds = [];
        $members = [];
        foreach ($this->storeUsers->findByStore($storeId) as $m) {
            $uid = (int) $m['user_id'];
            if (in_array($uid, $seenIds, true)) {
                continue;
            }
            $user = $this->users->findById($uid);
            if ($user !== null) {
                $seenIds[] = $uid;
                $members[] = $user;
            }
        }
        if ($keepUserId > 0 && !in_array($keepUserId, $seenIds, true)) {
            $user = $this->users->findById($keepUserId);
            if ($user !== null) {
                $members[] = $user;
            }
        }
        return $members;
    }

    private function showReport(Request $request): Response
    {
        [$report, $storeId, $reportId] = $this->findReportOrFail($request);
        $entity = $this->reportConfig()['entity'];

        $this->auditLogger->log($request, $entity . '.viewed', $entity, $reportId, [
            'store_id' => $storeId,
        ], $storeId);

        return Response::html($this->view->render($this->reportConfig()['view'] . '-show', array_merge([
            'title'  => $this->reportShowTitle($report),
            'store'  => $this->stores->findById($storeId),
            'report' => $report,
        ], $this->reportShowExtras($storeId, $report)), 'layout.app'));
    }

    /** Données additionnelles pour la vue show/pdf, propres à un type de rapport (voir AdminSalaryReportController). */
    protected function reportShowExtras(int $storeId, array $report): array
    {
        return [];
    }

    private function editReport(Request $request): Response
    {
        [$report, $storeId] = $this->findReportOrFail($request);

        return Response::html($this->view->render($this->reportConfig()['view'] . '-form', array_merge([
            'title'  => $this->reportEditTitle(),
            'store'  => $this->stores->findById($storeId),
            'mode'   => 'edit',
            'report' => $report,
        ], $this->reportEditExtras($storeId, $report)), 'layout.app'));
    }

    private function updateReport(Request $request): Response
    {
        [$report, $storeId, $reportId] = $this->findReportOrFail($request);
        $cfg = $this->reportConfig();

        $changes = $this->postData($request, $cfg['fields']);
        $userId = (int) $request->post('user_id', 0);
        $this->assertReportUserInStore($userId, $storeId);
        $changes = ['user_id' => $userId > 0 ? $userId : null] + $changes;
        $data = array_merge($report, $changes);

        $this->reportRepo()->save($data);
        $this->auditLogger->logUpdate($request, $cfg['entity'] . '.updated', $cfg['entity'], $reportId, $report, $data, [
            'store_id' => $storeId,
        ]);

        return $this->redirectToList($storeId, $cfg['slug'], 'updated');
    }

    /**
     * L'employé rattaché à un rapport doit être membre du magasin du rapport : sinon un gérant
     * pouvait lier le rapport d'un magasin à un compte d'un autre magasin. Sans effet si la
     * classe utilisatrice n'a pas de `$storeUsers` (anciennes versions de bundle) ou si aucun
     * employé n'est choisi (user_id = 0). Utilisable aussi par les bundles à la création.
     *
     * @throws ValidationException
     */
    protected function assertReportUserInStore(int $userId, int $storeId): void
    {
        if ($userId <= 0 || !isset($this->storeUsers)) {
            return;
        }
        if ($this->storeUsers->findMembership($storeId, $userId) === null) {
            throw new ValidationException(['user_id' => __('error_report_user_not_in_store')]);
        }
    }

    private function deleteReport(Request $request): Response
    {
        [, $storeId, $reportId] = $this->findReportOrFail($request);
        $entity = $this->reportConfig()['entity'];

        $this->reportRepo()->delete($reportId);
        $this->auditLogger->log($request, $entity . '.deleted', $entity, $reportId, [
            'store_id' => $storeId,
        ]);

        return $this->redirectToList($storeId, $this->reportConfig()['slug'], 'deleted');
    }

    /**
     * Aperçu HTML du PDF (route .../pdf) : pas de téléchargement automatique,
     * juste la même vue *-pdf.php rendue directement dans le navigateur avec
     * une barre d'outils (impression / téléchargement réel / fermer), comme
     * l'ancienne fiche de paie autonome. Le vrai fichier PDF n'est généré que
     * si l'utilisateur clique explicitement sur "Télécharger" (reportPdfDownload()).
     */
    private function reportPdf(Request $request): Response
    {
        [$report, $storeId, $reportId] = $this->findReportOrFail($request);
        $cfg = $this->reportConfig();

        $html = $this->view->render($cfg['view'] . '-pdf', array_merge([
            'report'      => $report,
            'store'       => $this->stores->findById($storeId),
            'downloadUrl' => $this->base() . '/admin/stores/' . $storeId . '/reports/' . $cfg['slug'] . '/' . $reportId . '/pdf/download',
        ], $this->reportShowExtras($storeId, $report)));

        return Response::html($html);
    }

    /** Génération réelle du fichier PDF (mPDF), déclenchée depuis la barre d'outils de l'aperçu. */
    private function reportPdfDownload(Request $request): Response
    {
        [$report, $storeId, $reportId] = $this->findReportOrFail($request);
        $cfg = $this->reportConfig();

        $html = $this->view->render($cfg['view'] . '-pdf', array_merge([
            'report' => $report,
            'store'  => $this->stores->findById($storeId),
        ], $this->reportShowExtras($storeId, $report)));

        return $this->renderPdf($html, $cfg['entity'] . '_' . $reportId . '.pdf');
    }

    private function redirectToList(int $storeId, string $slug, string $flag): Response
    {
        return Response::redirect($this->base() . '/admin/stores/' . $storeId . '/reports/' . $slug . '?success=' . $flag);
    }

    /**
     * Liste des stores visibles et filtre store_id pour les vues « tous les rapports ».
     *
     * @return array{0: array, 1: array, 2: int} [allStores, queryStoreIds, filterStoreId]
     */
    private function storesAndFilter(Request $request): array
    {
        $authUser = $request->getAttribute('auth_user');
        $managedIds = $request->getAttribute('managed_store_ids');

        // `managed_store_ids === null` = portée illimitée : Owner, mais aussi un rôle personnalisé
        // accordant la permission « Toutes les boutiques » (tester `is_admin` seul les excluait).
        $unrestricted = !empty($authUser['is_admin']) || $managedIds === null;
        if ($unrestricted) {
            $allStores = $this->stores->findAll();
            $storeIds = [];
        } else {
            $allStores = array_values(array_filter(
                array_map(fn($id) => $this->stores->findById($id), $managedIds ?? []),
            ));
            $storeIds = $managedIds ?? [];
        }

        $filterStoreId = (int) $request->query('store_id', '0');
        $queryStoreIds = $storeIds;
        if ($filterStoreId > 0) {
            if (!$unrestricted && !in_array($filterStoreId, $storeIds, true)) {
                throw new \kintai\Core\Exceptions\ForbiddenException(__('error_store_access_denied'));
            }
            $queryStoreIds = [$filterStoreId];
        }

        return [$allStores, $queryStoreIds, $filterStoreId];
    }

    private function findStoreOrFail(int $storeId): array
    {
        $store = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        return $store;
    }

    private function renderPdf(string $html, string $filename): Response
    {
        $tmpDir = storage_path('app/mpdf');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $config = PdfCjkFontResolver::applyTo([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => 16,
            'margin_bottom' => 16,
            'tempDir'       => $tmpDir,
        ]);

        $mpdf = new \Mpdf\Mpdf($config);
        $mpdf->SetTitle($filename);
        $mpdf->WriteHTML($html);

        return Response::pdf($mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), $filename);
    }

    /**
     * Retourne la liste des responsables (Owner + admin/manager du store) pour le
     * store courant ou pour tous les stores d'un employé donné. Utilisée par
     * démission et salaire. Nécessite $this->storeUsers (StoreUserRepositoryInterface)
     * et $this->users (UserRepositoryInterface) sur la classe utilisatrice.
     *
     * L'Owner est toujours inclus même si non listé dans store_user : voir
     * install.php, qui ne crée qu'une ligne role_assignments globale pour lui,
     * pas d'adhésion à un store précis.
     */
    private function getManagersForReportForm(int $storeId, int $userId = 0): array
    {
        $storeIds = [$storeId];
        if ($userId > 0) {
            $memberships = $this->storeUsers->findByUser($userId);
            $userStoreIds = array_map(fn($m) => (int) $m['store_id'], $memberships);
            if (!empty($userStoreIds)) {
                $storeIds = $userStoreIds;
            }
        }

        $ownerIds = $this->permissions->ownerUserIds();
        $seenIds = [];
        $managers = [];
        foreach ($this->users->findAll() as $u) {
            if (in_array((int) $u['id'], $ownerIds, true)) {
                $seenIds[] = (int) $u['id'];
                $managers[] = $u;
            }
        }
        foreach ($storeIds as $sid) {
            $members = $this->storeUsers->findByStore($sid);
            foreach ($members as $m) {
                $uid = (int) $m['user_id'];
                if (in_array($uid, $seenIds, true)) {
                    continue;
                }
                $candidate = $this->users->findById($uid);
                if ($candidate === null) {
                    continue;
                }
                if ($this->permissions->can($candidate, 'employees.update', $sid)) {
                    $seenIds[] = $uid;
                    $managers[] = $candidate;
                }
            }
        }
        return $managers;
    }
}
