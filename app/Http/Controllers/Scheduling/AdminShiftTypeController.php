<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use Illuminate\Http\Request;
use App\Validation\ColorRule;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Http\Controllers\HasAdminAccess;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class AdminShiftTypeController
{
    use HasAdminAccess;
    public function __construct(
        private readonly ViewFactory $view,
        private readonly StoreRepositoryInterface $stores,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function shiftTypes(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $types = $managedIds === null
            ? ShiftType::findAll()
            : ShiftType::findByStores($managedIds);

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

        return response($this->view->make('scheduling.shift-types', [
            'title'          => 'Types de shifts',
            'shift_types'    => $types,
            'type_store_ids' => $typeStoreIds,
            'locked_type_ids' => $lockedTypeIds,
            'all_stores'     => $this->availableStores($managedIds),
            'sort'           => $sort,
        ])->render());
    }

    public function createShiftType(Request $request): Response
    {
        return response($this->view->make('scheduling.shift-types-form', [
            'title'              => 'Nouveau type de shift',
            'mode'               => 'create',
            'shift_type'         => [],
            'all_stores'         => $this->availableStores($this->managedIds($request)),
            'selected_store_ids' => [],
        ])->render());
    }

    public function storeShiftType(Request $request): Response
    {
        ColorRule::assert($request->post('color', '#6366f1'));
        $storeIds = $this->postedStoreIds($request);
        if ($storeIds === []) {
            return redirect()->away($this->base() . '/admin/shift-types/create')->with('error', 'store_required');
        }
        $this->assertAnyStoreAccess($request, $storeIds);

        $savedType = ShiftType::put([
            'code'        => strtoupper(trim($request->post('code', ''))),
            'name'        => $request->post('name', ''),
            'start_time'  => $request->post('start_time', '08:00'),
            'end_time'    => $request->post('end_time', '16:00'),
            'color'       => $request->post('color', '#6366f1'),
            'hourly_rate' => (float) $request->post('hourly_rate', 0),
            'is_active'   => 1,
        ]);
        ShiftType::syncStores((int) $savedType['id'], $storeIds);

        $this->auditLogger->log($request, 'shift_type.created', 'shift_type', (int) ($savedType['id'] ?? 0), ['code' => $request->post('code', ''), 'name' => $request->post('name', '')], $storeIds[0]);

        return redirect()->away($this->base() . '/admin/shift-types')->with('success', 'created');
    }

    public function editShiftType(Request $request): Response
    {
        $type = ShiftType::findById((int) $request->route('id'));
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }
        $storeIds = ShiftType::getStoreIds((int) $type['id']);
        $this->assertAnyStoreAccess($request, $storeIds);
        $this->assertManagesEveryStore($request, $storeIds);

        return response($this->view->make('scheduling.shift-types-form', [
            'title'              => 'Modifier ' . htmlspecialchars($type['name'] ?? ''),
            'mode'               => 'edit',
            'shift_type'         => $type,
            'all_stores'         => $this->availableStores($this->managedIds($request)),
            'selected_store_ids' => $storeIds,
        ])->render());
    }

    public function updateShiftType(Request $request): Response
    {
        $type = ShiftType::findById((int) $request->route('id'));
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }
        $existingStoreIds = ShiftType::getStoreIds((int) $type['id']);
        $this->assertAnyStoreAccess($request, $existingStoreIds);
        $this->assertManagesEveryStore($request, $existingStoreIds);

        $submittedStoreIds = $this->postedStoreIds($request);
        if ($submittedStoreIds === []) {
            return redirect()->away($this->base() . '/admin/shift-types/' . $type['id'] . '/edit')->with('error', 'store_required');
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

        ShiftType::put($newData);
        ShiftType::syncStores((int) $type['id'], $submittedStoreIds);

        $this->auditLogger->logUpdate($request, 'shift_type.updated', 'shift_type', (int) $type['id'], $type, $newData, [], $submittedStoreIds[0] ?? null);

        return redirect()->away($this->base() . '/admin/shift-types')->with('success', 'updated');
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
        $id   = (int) $request->route('id');
        $type = ShiftType::findById($id);
        $storeIds = $type !== null ? ShiftType::getStoreIds($id) : [];
        if ($type !== null) {
            $this->assertAnyStoreAccess($request, $storeIds);
            $this->assertManagesEveryStore($request, $storeIds);
        }
        ShiftType::remove($id);
        $this->auditLogger->log($request, 'shift_type.deleted', 'shift_type', $id, ['code' => $type['code'] ?? '', 'name' => $type['name'] ?? ''], $storeIds[0] ?? null);
        return redirect()->away($this->base() . '/admin/shift-types')->with('success', 'deleted');
    }

    public function toggleShiftTypeStore(Request $request): Response
    {
        $id   = (int) $request->route('id');
        $type = ShiftType::findById($id);
        if ($type === null) {
            throw new NotFoundException(__('error_shift_type_not_found'));
        }

        $storeId = (int) $request->post('store_id', '0');
        $this->assertStoreAccess($request, $storeId);

        $enabled = $request->post('enabled', '0') === '1';

        if (!$enabled) {
            $remaining = array_diff(ShiftType::getStoreIds($id), [$storeId]);
            if ($remaining === []) {
                return redirect()->away($this->base() . '/admin/shift-types')->with('error', 'store_required');
            }
            ShiftType::disableForStore($id, $storeId);
        } else {
            ShiftType::enableForStore($id, $storeId);
        }

        $this->auditLogger->log(
            $request,
            $enabled ? 'shift_type.store_enabled' : 'shift_type.store_disabled',
            'shift_type',
            $id,
            ['store_id' => $storeId],
            $storeId,
        );

        return redirect()->away($this->base() . '/admin/shift-types')->with('success', $enabled ? 'store_enabled' : 'store_disabled');
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
            $map[$id] = ShiftType::getStoreIds($id);
        }
        return $map;
    }
}
