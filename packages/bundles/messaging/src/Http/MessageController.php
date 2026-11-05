<?php

namespace Kintai\Bundles\Messaging\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\MessagingActions;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Kintai\Contracts\Api\ApiController;
use Kintai\Contracts\Notifications\Notifier;

/**
 * API de la messagerie (`/api/v1/messaging`). Mêmes règles que les écrans : strictement limitée aux conversations dont le
 * porteur du jeton est participant (une conversation étrangère est introuvable, 404, comme une conversation absente),
 * destinataires limités aux collègues du magasin, suppression de ses seuls messages.
 */
class MessageController extends ApiController
{
    public function __construct(private readonly MessagingAccess $access, private readonly MessagingActions $actions) {}

    public function threads(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = $request->validate(['tab' => ['nullable', Rule::in(['inbox', 'sent', 'unread'])], 'store_id' => ['nullable', 'integer']]);

        $threads = $this->access->listing($user, $filters['tab'] ?? 'inbox')
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->with(['store:id,name', 'latestMessage.sender:id,name'])
            ->withCount('messages')
            ->paginate($this->perPage($request));

        $read = ThreadParticipant::query()->where('user_id', $user->id)->whereIn('thread_id', $threads->pluck('id'))->pluck('is_read', 'thread_id');

        return MessageResource::collection($threads->through(fn (MessageThread $thread): array => $this->presentThread($thread, ! (bool) ($read[$thread->id] ?? true))));
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->access->unreadCount($this->user($request))]]);
    }

    public function show(Request $request, int $thread): MessageResource
    {
        $user = $this->user($request);
        $model = $this->find($user, $thread);
        $model->load(['store:id,name', 'latestMessage.sender:id,name'])->loadCount('messages');

        return new MessageResource($this->presentThread($model, $this->unreadFor($user, $model), true));
    }

    /**
     * Ouvre une conversation avec un premier message.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:'.MessagingActions::SUBJECT_MAX],
            'body' => ['required', 'string', 'max:'.MessagingActions::BODY_MAX],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer'],
        ]);

        $thread = $this->actions->start($user, (int) $data['store_id'], $data['subject'], $data['body'], array_map('intval', $data['recipient_ids']));
        $thread->load(['store:id,name', 'latestMessage.sender:id,name'])->loadCount('messages');

        return (new MessageResource($this->presentThread($thread, false, true)))->response()->setStatusCode(201);
    }

    /**
     * Cache la conversation de la liste de l'appelant seul.
     */
    public function destroy(Request $request, int $thread): Response
    {
        $user = $this->user($request);
        $this->actions->hide($user, $this->find($user, $thread));

        return response()->noContent();
    }

    /**
     * Les messages de la conversation, du plus ancien au plus récent ; les lire la marque lue pour l'appelant.
     */
    public function messages(Request $request, int $thread): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $model = $this->find($user, $thread);

        $messages = $model->messages()->with('sender:id,name')->orderBy('sent_at')->orderBy('id')->paginate($this->perPage($request));

        $this->actions->markRead($user, $model);
        app(Notifier::class)->markRead($user, 'message_thread', $model->id);

        return MessageResource::collection($messages->through(fn (ThreadMessage $message): array => $this->presentMessage($user, $message)));
    }

    public function send(Request $request, int $thread): JsonResponse
    {
        $user = $this->user($request);
        $model = $this->find($user, $thread);
        $data = $request->validate(['body' => ['required', 'string', 'max:'.MessagingActions::BODY_MAX]]);

        $message = $this->actions->reply($user, $model, $data['body']);
        $message->load('sender:id,name');

        return (new MessageResource($this->presentMessage($user, $message)))->response()->setStatusCode(201);
    }

    /**
     * Supprime l'un de ses propres messages.
     */
    public function destroyMessage(Request $request, int $message): Response
    {
        $user = $this->user($request);
        $model = ThreadMessage::query()->find($message);

        if ($model === null || $this->access->participation($user, $model->thread_id) === null) {
            abort(404);
        }

        $this->actions->deleteMessage($user, $model);

        return response()->noContent();
    }

    public function participants(Request $request, int $thread): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $model = $this->find($user, $thread);

        return MessageResource::collection($model->participants()->with('user:id,name')->get()->map(fn (ThreadParticipant $participant): array => $this->presentParticipant($participant)));
    }

    public function addParticipant(Request $request, int $thread): JsonResponse
    {
        $user = $this->user($request);
        $model = $this->find($user, $thread);
        $data = $request->validate(['user_id' => ['required', 'integer']]);

        $participant = $this->actions->addParticipant($user, $model, (int) $data['user_id']);
        $participant->load('user:id,name');

        return (new MessageResource($this->presentParticipant($participant)))->response()->setStatusCode(201);
    }

    private function user(Request $request): User
    {
        $user = $this->actor($request);
        assert($user instanceof User);

        return $user;
    }

    private function find(User $user, int $thread): MessageThread
    {
        return $this->access->thread($user, $thread) ?? abort(404);
    }

    private function unreadFor(User $user, MessageThread $thread): bool
    {
        return ! (bool) ($this->access->participation($user, $thread->id)->is_read ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentThread(MessageThread $thread, bool $unread, bool $withParticipants = false): array
    {
        $last = $thread->latestMessage;

        $data = [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'store' => ['id' => $thread->store_id, 'name' => $thread->store->name],
            'creator_id' => $thread->creator_id,
            'unread' => $unread,
            'messages_count' => (int) ($thread->getAttribute('messages_count') ?? 0),
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'last_message' => $last === null ? null : ['sender' => ['id' => $last->sender_id, 'name' => $last->sender?->name], 'sent_at' => $last->sent_at->toIso8601String()],
            'created_at' => $thread->created_at?->toIso8601String(),
        ];

        if ($withParticipants) {
            $data['participants'] = $thread->participants()->with('user:id,name')->get()->map(fn (ThreadParticipant $participant): array => $this->presentParticipant($participant))->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(User $user, ThreadMessage $message): array
    {
        return [
            'id' => $message->id,
            'thread_id' => $message->thread_id,
            'sender' => ['id' => $message->sender_id, 'name' => $message->sender?->name],
            'body' => $message->isErased() ? null : $message->body,
            'erased' => $message->isErased(),
            'sent_at' => $message->sent_at->toIso8601String(),
            'mine' => $message->sender_id === $user->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentParticipant(ThreadParticipant $participant): array
    {
        return ['user' => ['id' => $participant->user_id, 'name' => $participant->user?->name]];
    }
}
