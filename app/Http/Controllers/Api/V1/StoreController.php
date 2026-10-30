<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Paginator;
use App\Auth\PermissionService;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Store;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\StoreServiceInterface;
use App\Validation\ApiPayloadRules;
use App\Validation\PayloadValidator;

final class StoreController
{
    public function __construct(
        private readonly StoreServiceInterface $storeService,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/stores?page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $authUser = $this->authUser($request);
        $items    = Store::findAll();

        if (!$this->permissions->hasUnrestrictedGrant($authUser, 'stores.view')) {
            $items = array_values(array_filter(
                $items,
                fn(array $s): bool => $this->permissions->can($authUser, 'stores.view', (int) $s['id'])
            ));
        }

        return response()->json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/stores/{id} */
    public function show(Request $request): Response
    {
        return response()->json($this->requireStore($request, 'stores.view'));
    }

    /** POST /api/v1/stores */
    public function store(Request $request): Response
    {
        $data = $request->json()->all() ?? [];
        unset($data['id']);
        $saved = $this->storeService->createStore($data);
        $this->auditLogger->log($request, 'store.created', 'store', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data);
        return response()->json($saved, 201);
    }

    /** PUT /api/v1/stores/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireStore($request, 'stores.update');
        $id  = (int) $old['id'];

        // Mise à jour partielle : seuls les champs fournis changent, chacun contrôlé (la devise comme le
        // formulaire web, car elle s'affiche dans les statistiques).
        $data = PayloadValidator::validateUpdate($request->json()->all() ?? [], ApiPayloadRules::storeUpdate());
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
            $other = Store::findByCode($data['code']);
            if ($other !== null && (int) $other['id'] !== $id) {
                $message = __('val_store_code_taken', ['code' => $data['code']]);
                throw new ValidationException(['code' => $message], $message);
            }
        }

        $saved = Store::put(array_merge($data, ['id' => $id]));
        $this->auditLogger->logUpdate($request, 'store.updated', 'store', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data);
        return response()->json($saved);
    }

    /** DELETE /api/v1/stores/{id} */
    public function destroy(Request $request): Response
    {
        $old = $this->requireStore($request, 'stores.delete');
        $id  = (int) $old['id'];

        $this->storeService->deleteStore($id);
        $this->auditLogger->log($request, 'store.deleted', 'store', resourceId: $id);
        return response()->noContent();
    }

    private function authUser(Request $request): array
    {
        return $request->attributes->get('auth_user') ?? [];
    }

    /** Charge le magasin par id et vérifie $permissionKey sur ce magasin (jamais « n'importe lequel »). */
    private function requireStore(Request $request, string $permissionKey): array
    {
        $id    = (int) $request->route('id');
        $store = Store::findById($id);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        $this->permissions->requireOnStore($this->authUser($request), $permissionKey, $id);
        return $store;
    }
}
