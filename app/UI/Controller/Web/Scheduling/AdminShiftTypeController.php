<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\Scheduling;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Validation\ColorRule;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\HasAdminAccess;
use kintai\UI\ViewRenderer;

final class AdminShiftTypeController
{
    use HasAdminAccess;
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly StoreRepositoryInterface $stores,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function shiftTypes(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $types = $managedIds === null
            ? $this->shiftTypes->findAll()
            : $this->shiftTypes->findByStores($managedIds);

        $typeStoreIds = $this->buildTypeStoreIds($types);

        $sort = $request->query('sort') ?? 'name_asc';
        usort($types, function ($a, $b) use ($sort) {
            $codeA   = strtolower($a['code'] ?? '');
            $codeB   = strtolower($b['code'] ?? '');
            $nameA   = strtolower($a['name'] ?? '');
            $nameB   = strtolower($b['name'] ?? '');
            $statA   = (int) !empty($a['is_active']);
            $statB   = (int) !empty($b['is_active']);
            return match ($sort) {
                'name_desc'    => strcmp($nameB, $nameA),
                'code_asc'     => strcmp($codeA, $codeB) ?: strcmp($nameA, $nameB),
                'code_desc'    => strcmp($codeB, $codeA) ?: strcmp($nameA, $nameB),
                'status_asc'   => $statA <=> $statB ?: strcmp($nameA, $nameB),
                'status_desc'  => $statB <=> $statA ?: strcmp($nameA, $nameB),
                default        => strcmp($nameA, $nameB),
            };
        });

        // Types partagés avec un magasin que l'utilisateur ne gère pas : ni modifiables ni supprimables par lui (même
        // règle que assertManagesEveryStore()). La liste ne propose donc plus leurs liens, qui menaient à un 403.
        $lockedTypeIds = [];
        if ($managedIds !== null) {
            foreach ($typeStoreIds as $typeId => $storeIds) {
                if (array_diff(array_map('intval', $storeIds), $managedIds) !== []) {
                    $lockedTypeIds[] = (int) $typeId;
                }
            }
        }

        return Response::html($this->view->render('scheduling.shift-types', [
            'title'          => 'Types de shifts',
            'shift_types'    => $types,
            'type_store_ids' => $typeStoreIds,
            'locked_type_ids' => $lockedTypeIds,
            'all_stores'     => $this->availableStores($managedIds),
            'sort'           => $sort,
        ], 'layout.app'));
    }

    public function createShiftType(Request $request): Response
    {
        return Response::html($this->view->render('scheduling.shift-types-form', [
            'title'              => 'Nouveau type de shift',
            'mode'               => 'create',
            'shift_type'         => [],
            'all_stores'         => $this->availableStores($this->managedIds($request)),
            'selected_store_ids' => [],
        ], 'layout.app'));
    }

    public function storeShiftType(Request $request): Response
    {
        ColorRule::assert($request->post('color', '#6366f1'));
        $storeIds = $this->postedStoreIds($request);
        if ($storeIds === []) {
            return Response::redirect($this->base() . '/admin/shift-types/create?error=store_required');
        }
        $this->assertAnyStoreAccess($request, $storeIds);

        $savedType = $this->shiftTypes->save([
            'code'        => strtoupper(trim($request->post('code', ''))),
            'name'        => $request->post('name', ''),
            'start_time'  => $request->post('start_time', '08:00'),
            'end_time'    => $request->post('end_time', '16:00'),
            'color'       => $request->post('color', '#6366f1'),
            'hourly_rate' => (float) $request->post('hourly_rate', 0),
            'is_active'   => 1,
        ]);
        $this->shiftTypes->syncStores((int) $savedType['id'], $storeIds);

        $this->auditLogger->log($request, 'shift_type.created', 'shift_type', (int) ($savedType['id'] ?? 0), ['code' => $request->post('code', ''), 'name' => $request->post('name', '')], $storeIds[0]);

        return Response::redirect($this->base() . '/admin/shift-types?success=created');
    }

    public function editShiftType(Request $request): Response
    {
        $type = $this->shiftTypes->findById((int) $request->param('id'));
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }
        $storeIds = $this->shiftTypes->getStoreIds((int) $type['id']);
        $this->assertAnyStoreAccess($request, $storeIds);
        $this->assertManagesEveryStore($request, $storeIds);

        return Response::html($this->view->render('scheduling.shift-types-form', [
            'title'              => 'Modifier ' . htmlspecialchars($type['name'] ?? ''),
            'mode'               => 'edit',
            'shift_type'         => $type,
            'all_stores'         => $this->availableStores($this->managedIds($request)),
            'selected_store_ids' => $storeIds,
        ], 'layout.app'));
    }

    public function updateShiftType(Request $request): Response
    {
        $type = $this->shiftTypes->findById((int) $request->param('id'));
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }
        $existingStoreIds = $this->shiftTypes->getStoreIds((int) $type['id']);
        $this->assertAnyStoreAccess($request, $existingStoreIds);
        $this->assertManagesEveryStore($request, $existingStoreIds);

        $submittedStoreIds = $this->postedStoreIds($request);
        if ($submittedStoreIds === []) {
            return Response::redirect($this->base() . '/admin/shift-types/' . $type['id'] . '/edit?error=store_required');
        }

        // Un gestionnaire de store ne voit dans son formulaire que les stores qu'il
        // gère (availableStores) : les affectations existantes à des stores hors de
        // sa portée ne doivent pas disparaître faute d'avoir pu être re-cochées.
        $managedIds = $this->managedIds($request);
        if ($managedIds !== null) {
            $visibleStoreIds  = array_map(fn($s) => (int) $s['id'], $this->availableStores($managedIds));
            $submittedStoreIds = array_values(array_intersect($submittedStoreIds, $visibleStoreIds));
            $outOfScopeStoreIds = array_values(array_diff($existingStoreIds, $visibleStoreIds));
            $submittedStoreIds = array_values(array_unique(array_merge($submittedStoreIds, $outOfScopeStoreIds)));
        }

        ColorRule::assert($request->post('color', $type['color'] ?? '#6366f1'));

        $newData = array_merge($type, [
            'code'        => strtoupper(trim($request->post('code', $type['code'] ?? ''))),
            'name'        => $request->post('name', $type['name'] ?? ''),
            'start_time'  => $request->post('start_time', $type['start_time'] ?? '08:00'),
            'end_time'    => $request->post('end_time', $type['end_time'] ?? '16:00'),
            'color'       => $request->post('color', $type['color'] ?? '#6366f1'),
            // Champ absent : valeur actuelle (audit M12) ; vide : 0 (la colonne n'accepte pas null).
            'hourly_rate' => $request->hasPost('hourly_rate') ? (float) $request->post('hourly_rate', 0) : (float) ($type['hourly_rate'] ?? 0),
            'is_active'   => $request->postFlag('is_active', $type['is_active'] ?? 1),
        ]);

        $this->shiftTypes->save($newData);
        $this->shiftTypes->syncStores((int) $type['id'], $submittedStoreIds);

        $this->auditLogger->logUpdate($request, 'shift_type.updated', 'shift_type', (int) $type['id'], $type, $newData, [], $submittedStoreIds[0] ?? null);

        return Response::redirect($this->base() . '/admin/shift-types?success=updated');
    }

    /**
     * Un type de shift est partagé entre les magasins où il est activé : le modifier ou le supprimer change les
     * shifts et la paie de tous. Il faut donc gérer chacun de ces magasins (ou n'avoir aucune restriction de
     * magasin). Un gérant peut en revanche l'activer ou le désactiver pour son propre magasin (toggleShiftTypeStore).
     *
     * @param int[] $typeStoreIds
     */
    private function assertManagesEveryStore(Request $request, array $typeStoreIds): void
    {
        $managedIds = $this->managedIds($request);
        if ($managedIds === null) {
            return;
        }
        if (array_diff(array_map('intval', $typeStoreIds), $managedIds) !== []) {
            throw new ForbiddenException(__('error_shared_shift_type'));
        }
    }

    public function deleteShiftType(Request $request): Response
    {
        $id   = (int) $request->param('id');
        $type = $this->shiftTypes->findById($id);
        $storeIds = $type !== null ? $this->shiftTypes->getStoreIds($id) : [];
        if ($type !== null) {
            $this->assertAnyStoreAccess($request, $storeIds);
            $this->assertManagesEveryStore($request, $storeIds);
        }
        $this->shiftTypes->delete($id);
        $this->auditLogger->log($request, 'shift_type.deleted', 'shift_type', $id, ['code' => $type['code'] ?? '', 'name' => $type['name'] ?? ''], $storeIds[0] ?? null);
        return Response::redirect($this->base() . '/admin/shift-types?success=deleted');
    }

    public function toggleShiftTypeStore(Request $request): Response
    {
        $id   = (int) $request->param('id');
        $type = $this->shiftTypes->findById($id);
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }

        $storeId = (int) $request->post('store_id', '0');
        $this->assertStoreAccess($request, $storeId);

        $enabled = $request->post('enabled', '0') === '1';

        if (!$enabled) {
            $remaining = array_diff($this->shiftTypes->getStoreIds($id), [$storeId]);
            if ($remaining === []) {
                return Response::redirect($this->base() . '/admin/shift-types?error=store_required');
            }
            $this->shiftTypes->disableForStore($id, $storeId);
        } else {
            $this->shiftTypes->enableForStore($id, $storeId);
        }

        $this->auditLogger->log(
            $request,
            $enabled ? 'shift_type.store_enabled' : 'shift_type.store_disabled',
            'shift_type',
            $id,
            ['store_id' => $storeId],
            $storeId,
        );

        return Response::redirect($this->base() . '/admin/shift-types?success=' . ($enabled ? 'store_enabled' : 'store_disabled'));
    }

    /** @return int[] */
    private function postedStoreIds(Request $request): array
    {
        $ids = $request->post('store_ids', []);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return array<int, int[]> id de type => IDs de stores affectés */
    private function buildTypeStoreIds(array $types): array
    {
        $map = [];
        foreach ($types as $t) {
            $id = (int) $t['id'];
            $map[$id] = $this->shiftTypes->getStoreIds($id);
        }
        return $map;
    }
}
