<?php

namespace Kintai\Bundles\Messaging;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;

/**
 * Écritures de la messagerie, communes aux écrans et à l'API : règles d'envoi, état lu / non lu, conversation masquée,
 * journal (jamais le contenu des messages) et notifications. Les appelants ont déjà vérifié que l'utilisateur participe à la
 * conversation (`MessagingAccess::thread()`).
 *
 * Un refus de droit lève `AuthorizationException`, une règle non respectée une `ValidationException`.
 */
class MessagingActions
{
    public const BODY_MAX = 5000;

    public const SUBJECT_MAX = 255;

    public function __construct(
        private readonly MessagingAccess $access,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Ouvre une conversation dans un magasin avec un premier message.
     *
     * @param  list<int>  $recipientIds  Collègues du magasin (l'expéditeur et les doublons sont écartés).
     *
     * @throws AuthorizationException Sans `messaging.create` pour ce magasin.
     * @throws ValidationException Magasin où l'on ne peut pas écrire, destinataire qui n'est pas un collègue visible, champ vide.
     */
    public function start(User $sender, int $storeId, string $subject, string $body, array $recipientIds): MessageThread
    {
        if (! $this->access->can($sender, 'messaging.create')) {
            throw new AuthorizationException(__('You are not allowed to start a conversation.'));
        }

        $subject = trim($subject);
        $body = trim($body);
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), fn (int $id): bool => $id > 0 && $id !== $sender->id)));

        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX) {
            throw ValidationException::withMessages(['subject' => __('The subject is required (255 characters at most).')]);
        }

        $this->assertBody($body);

        if (! $this->access->canWriteIn($sender, $storeId)) {
            throw ValidationException::withMessages(['storeId' => __('You cannot send messages in this store.')]);
        }

        if (! $this->access->canIn($sender, 'messaging.create', $storeId)) {
            throw new AuthorizationException(__('You are not allowed to start a conversation.'));
        }

        $allowed = array_map(fn ($colleague): int => $colleague->id, $this->access->recipients($sender, $storeId));

        if ($recipientIds === [] || array_diff($recipientIds, $allowed) !== []) {
            throw ValidationException::withMessages(['recipientIds' => __('Choose at least one colleague of the store.')]);
        }

        $now = Carbon::now();

        $thread = DB::transaction(function () use ($sender, $storeId, $subject, $body, $recipientIds, $now): MessageThread {
            $thread = MessageThread::create(['store_id' => $storeId, 'creator_id' => $sender->id, 'subject' => $subject, 'last_message_at' => $now]);
            ThreadMessage::create(['thread_id' => $thread->id, 'sender_id' => $sender->id, 'body' => $body, 'sent_at' => $now]);
            ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $sender->id, 'is_read' => true, 'last_read_at' => $now]);

            foreach ($recipientIds as $recipientId) {
                ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $recipientId, 'is_read' => false]);
            }

            return $thread;
        });

        $this->activity->record('message.sent', $thread, $storeId, ['recipients' => count($recipientIds)], $sender);
        $this->tell($recipientIds, $sender, $thread, 'New message from :sender: :subject');

        return $thread;
    }

    /**
     * Répond dans la conversation : elle redevient non lue, et réapparaît, chez ceux qui l'avaient masquée.
     *
     * @throws AuthorizationException Sans `messaging.send` pour le magasin de la conversation.
     * @throws ValidationException
     */
    public function reply(User $sender, MessageThread $thread, string $body): ThreadMessage
    {
        $this->assertCan($sender, 'messaging.send', $thread, 'You are not allowed to send messages.');

        $body = trim($body);
        $this->assertBody($body);

        if (! $this->access->canReply($sender, $thread)) {
            throw ValidationException::withMessages(['body' => __('You cannot send messages in this store.')]);
        }

        $now = Carbon::now();

        /** @var array{ThreadMessage, list<int>} $result */
        $result = DB::transaction(function () use ($sender, $thread, $body, $now): array {
            $message = ThreadMessage::create(['thread_id' => $thread->id, 'sender_id' => $sender->id, 'body' => $body, 'sent_at' => $now]);
            $thread->forceFill(['last_message_at' => $now])->save();

            $others = ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', '!=', $sender->id);
            $ids = $others->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $others->update(['is_read' => false, 'hidden_at' => null]);

            // La réponse de l'auteur le remet à jour : il a lu la conversation et la voit dans sa liste.
            ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $sender->id)
                ->update(['is_read' => true, 'last_read_at' => $now, 'hidden_at' => null]);

            return [$message, $ids];
        });

        [$message, $recipients] = $result;

        $this->activity->record('message.replied', $thread, $thread->store_id, ['message_id' => $message->id], $sender);
        $this->tell($recipients, $sender, $thread, ':sender replied in: :subject');

        return $message;
    }

    /**
     * Marque la conversation lue pour l'utilisateur.
     */
    public function markRead(User $user, MessageThread $thread): void
    {
        $this->assertCan($user, 'messaging.view', $thread, 'You are not allowed to read messages.');

        ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $user->id)->where('is_read', false)
            ->update(['is_read' => true, 'last_read_at' => Carbon::now()]);
    }

    /**
     * Supprime son propre message (jamais celui d'un autre, quel que soit son rôle).
     *
     * @throws AuthorizationException
     */
    public function deleteMessage(User $actor, ThreadMessage $message): void
    {
        if ($message->sender_id !== $actor->id) {
            throw new AuthorizationException;
        }

        $thread = $message->thread;
        $this->assertCan($actor, 'messaging.view', $thread, 'You are not allowed to read messages.');
        $message->delete();
        $this->activity->record('message.deleted', $thread, $thread->store_id, ['message_id' => $message->id], $actor);
    }

    /**
     * Retire la conversation de la liste de l'utilisateur seul ; elle réapparaît si quelqu'un y répond. Quand plus
     * personne ne la voit, elle est effacée.
     *
     * @return bool Vrai si la conversation a été effacée.
     */
    public function hide(User $user, MessageThread $thread): bool
    {
        $this->assertCan($user, 'messaging.view', $thread, 'You are not allowed to read messages.');

        $erased = DB::transaction(function () use ($user, $thread): bool {
            ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $user->id)
                ->update(['hidden_at' => Carbon::now(), 'is_read' => true]);

            if (ThreadParticipant::query()->where('thread_id', $thread->id)->whereNull('hidden_at')->exists()) {
                return false;
            }

            $thread->delete();

            return true;
        });

        if ($erased) {
            $this->activity->recordFor('message.thread_deleted', 'message_thread', $thread->id, $thread->store_id, [], $user);
        } else {
            $this->activity->record('message.thread_hidden', $thread, $thread->store_id, [], $user);
        }

        return $erased;
    }

    /**
     * Ajoute un collègue du magasin de la conversation (il y voit tout l'historique).
     *
     * @throws AuthorizationException Sans `messaging.send` pour le magasin de la conversation.
     * @throws ValidationException
     */
    public function addParticipant(User $actor, MessageThread $thread, int $userId): ThreadParticipant
    {
        $this->assertCan($actor, 'messaging.send', $thread, 'You are not allowed to send messages.');

        $allowed = array_map(fn ($colleague): int => $colleague->id, $this->access->recipients($actor, $thread->store_id));

        if (! in_array($userId, $allowed, true)) {
            throw ValidationException::withMessages(['user_id' => __('Choose at least one colleague of the store.')]);
        }

        $existing = ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $userId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $participant = ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $userId, 'is_read' => false]);
        $this->activity->record('message.participant_added', $thread, $thread->store_id, ['user_id' => $userId], $actor);
        $this->tell([$userId], $actor, $thread, 'New message from :sender: :subject');

        return $participant;
    }

    /**
     * Efface le contenu des messages d'un compte anonymisé : la conversation reste lisible pour les autres, ses messages
     * sont remplacés par une mention.
     */
    public function eraseMessagesOf(int $userId): void
    {
        ThreadMessage::query()->where('sender_id', $userId)->whereNull('erased_at')->update(['body' => '', 'erased_at' => Carbon::now()]);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCan(User $user, string $permission, MessageThread $thread, string $message): void
    {
        if (! $this->access->canIn($user, $permission, $thread->store_id)) {
            throw new AuthorizationException(__($message));
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertBody(string $body): void
    {
        if ($body === '' || mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => __('The message is required (5000 characters at most).')]);
        }
    }

    /**
     * @param  list<int>  $recipients
     */
    private function tell(array $recipients, User $sender, MessageThread $thread, string $body): void
    {
        $recipients = array_values(array_diff($recipients, [$sender->id]));

        if ($recipients === []) {
            return;
        }

        $this->notifier->notify(
            $recipients, MessagingServiceProvider::NOTIFICATION, $body,
            ['sender' => $sender->name, 'subject' => Str::limit($thread->subject, 60)],
            route('messaging.thread', ['id' => $thread->id], false), $thread->id,
        );
    }
}
