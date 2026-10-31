<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\Notebook\Controllers\Api;

use kintai\Bundles\Installed\Notebook\Models\NotebookEntry;
use kintai\Contract\Http\Paginator;
use kintai\Contract\Auth\PermissionService;
use kintai\Contract\Http\Request;
use kintai\Contract\Http\Response;

final class NotebookController
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/notebook-entries?store_id=X&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId = $request->query('store_id');

        $items = $storeId !== null
            ? NotebookEntry::findByStore((int) $storeId)
            : NotebookEntry::findAll();

        $items = $this->permissions->restrictToScope($this->authUser($request), 'notebook.view', $items);

        return response()->json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/notebook-entries/{id} */
    public function show(Request $request): Response
    {
        $item = $this->requireEntry($request, 'notebook.view');
        return response()->json($item);
    }

    /** POST /api/v1/notebook-entries */
    public function store(Request $request): Response
    {
        $authUser = $this->authUser($request);
        $body     = $request->json()->all() ?? [];

        // Liste blanche : ni id (upsert d'une note existante), ni author_id, ni pinned, ni created_at venus du client.
        $content = trim((string) ($body['content'] ?? ''));
        if ($content === '') {
            throw new \kintai\Contract\Exceptions\ValidationException(['content' => __('notebook_error_content_required')]);
        }
        $storeId = isset($body['store_id']) && $body['store_id'] !== '' ? (int) $body['store_id'] : null;

        // Comme sur le web : une note « organisation » exige une portée globale, une note de magasin le droit sur ce magasin.
        if ($storeId === null) {
            $userId = (int) ($authUser['id'] ?? 0);
            if ($this->permissions->scopedStoreIds($userId, 'notebook.create') !== []
                || !$this->permissions->can($authUser, 'notebook.create', null)) {
                throw new \kintai\Contract\Exceptions\ForbiddenException(__('notebook_error_forbidden'));
            }
        } elseif (!$this->permissions->can($authUser, 'notebook.create', $storeId)) {
            throw new \kintai\Contract\Exceptions\ForbiddenException(__('notebook_error_forbidden'));
        }

        return response()->json(NotebookEntry::put([
            'author_id'  => (int) ($authUser['id'] ?? 0),
            'store_id'   => $storeId,
            'content'    => $content,
            'pinned'     => 0,
            'expires_at' => $body['expires_at'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]), 201);
    }

    /** PUT /api/v1/notebook-entries/{id} */
    public function update(Request $request): Response
    {
        $item = $this->requireEntry($request, 'notebook.create');
        $id   = (int) $item['id'];
        $body = $request->json()->all() ?? [];

        // Seuls le texte et l'expiration sont modifiables : store_id, pinned (réservé à notebook.manage), created_at et author_id restent ceux de la note.
        $data = ['id' => $id];
        if (array_key_exists('content', $body)) {
            $content = trim((string) $body['content']);
            if ($content === '') {
                throw new \kintai\Contract\Exceptions\ValidationException(['content' => __('notebook_error_content_required')]);
            }
            $data['content'] = $content;
        }
        if (array_key_exists('expires_at', $body)) {
            $data['expires_at'] = $body['expires_at'];
        }
        return response()->json(NotebookEntry::put($data));
    }

    /** DELETE /api/v1/notebook-entries/{id} */
    public function destroy(Request $request): Response
    {
        $item = $this->requireEntry($request, 'notebook.create');
        NotebookEntry::remove((int) $item['id']);
        return response()->noContent();
    }

    private function authUser(Request $request): array
    {
        return $request->attributes->get('auth_user') ?? [];
    }

    /** Charge la note par id et vérifie $permissionKey OU l'auteur sur son store réel. */
    private function requireEntry(Request $request, string $permissionKey): array
    {
        $authUser = $this->authUser($request);
        $id       = (int) $request->route('id');
        $item     = NotebookEntry::findById($id);

        if ($item === null) {
            throw new \kintai\Contract\Exceptions\NotFoundException('Note introuvable.');
        }

        $isAuthor = (int) $item['author_id'] === (int) ($authUser['id'] ?? 0);
        $storeId  = $item['store_id'] !== null ? (int) $item['store_id'] : null;
        if (!$isAuthor && !$this->permissions->can($authUser, $permissionKey, $storeId)) {
            throw new \kintai\Contract\Exceptions\ForbiddenException(__('notebook_error_forbidden'));
        }

        return $item;
    }
}
