<?php

namespace Kintai\Bundles\Notebook\Http;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Kintai\Bundles\Notebook\Actions\NoteActions;
use Kintai\Bundles\Notebook\Http\Requests\IndexRequest;
use Kintai\Bundles\Notebook\Http\Requests\StoreRequest;
use Kintai\Bundles\Notebook\Http\Requests\UpdateRequest;
use Kintai\Bundles\Notebook\Models\NotebookEntry;
use Kintai\Bundles\Notebook\NotebookAccess;
use Kintai\Contracts\Api\ApiController;

/**
 * API du carnet (`/api/v1/notebook/entries`). Mêmes droits que la page : `notebook.view` pour lire (notes des magasins
 * où l'on peut lire, plus celles de toute l'organisation), `notebook.create` pour publier, l'auteur ou `notebook.manage`
 * pour modifier et supprimer, `notebook.manage` pour épingler. Une note hors du périmètre de l'appelant est introuvable.
 */
class EntryController extends ApiController
{
    public function __construct(private readonly NotebookAccess $access, private readonly NoteActions $actions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $this->requireView($user);

        $filters = app(IndexRequest::class)->validated();

        $entries = $this->access->visible($user, (bool) ($filters['include_expired'] ?? false))
            ->when(isset($filters['store_id']), fn ($query) => $query->where('store_id', (int) $filters['store_id']))
            ->when(isset($filters['pinned']), fn ($query) => $query->where('pinned', (bool) $filters['pinned']))
            ->paginate($this->perPage($request));

        return EntryResource::collection($entries->through(fn (NotebookEntry $entry): array => $this->present($user, $entry)));
    }

    public function show(Request $request, int $entry): EntryResource
    {
        $user = $this->user($request);

        return new EntryResource($this->present($user, $this->find($user, $entry)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requireView($user);

        $data = app(StoreRequest::class)->validated();

        $entry = $this->actions->publish($user, $data['store_id'] ?? null, $data['content'], $data['expires_on'] ?? null);

        return (new EntryResource($this->present($user, $entry->load(['author:id,name', 'store:id,name']))))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $entry): EntryResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $entry);

        // Seuls le texte et l'expiration changent ; une clé absente garde sa valeur.
        $data = app(UpdateRequest::class)->validated();

        $this->actions->update(
            $user, $model, $data['content'] ?? $model->content,
            array_key_exists('expires_on', $data) ? $data['expires_on'] : $model->expires_on?->toDateString(),
        );

        return new EntryResource($this->present($user, $model));
    }

    public function destroy(Request $request, int $entry): Response
    {
        $user = $this->user($request);
        $this->actions->delete($user, $this->find($user, $entry));

        return response()->noContent();
    }

    /**
     * Épingle la note, ou la désépingle si elle l'est.
     */
    public function pin(Request $request, int $entry): EntryResource
    {
        $user = $this->user($request);

        return new EntryResource($this->present($user, $this->actions->togglePin($user, $this->find($user, $entry))));
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    /**
     * @throws AuthorizationException
     */
    private function requireView(User $user): void
    {
        if (! Gate::forUser($user)->allows('notebook.view')) {
            throw new AuthorizationException;
        }
    }

    private function find(User $user, int $id): NotebookEntry
    {
        $this->requireView($user);

        $entry = NotebookEntry::query()->with(['author:id,name', 'store:id,name'])->findOrFail($id);

        if (! $this->access->canView($user, $entry)) {
            abort(404);
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, NotebookEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'content' => $entry->content,
            'pinned' => $entry->pinned,
            'store_id' => $entry->store_id,
            'store_name' => $entry->store?->name,
            'author' => ['id' => $entry->author_id, 'name' => $entry->author->name],
            'expires_on' => $entry->expires_on?->toDateString(),
            'expired' => $entry->isExpired(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'can_edit' => $this->access->canEdit($user, $entry),
            'can_pin' => $this->access->canManage($user, $entry),
        ];
    }
}
