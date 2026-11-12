<?php

namespace Kintai\Bundles\GoogleCalendar;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Bundles\GoogleCalendar\Models\CalendarEventLink;
use Kintai\Bundles\GoogleCalendar\Models\CalendarSyncQueueItem;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Planning\TimeOffPeriod;
use Kintai\Contracts\Planning\TimeOffProvider;
use Kintai\Contracts\Settings\Settings;
use Throwable;

/**
 * Synchronisation Kintai vers Google Agenda (jamais dans l'autre sens).
 *
 * Deux filets complémentaires :
 *  - temps réel : les écouteurs des événements de shift (`QueueShiftChanges`) mettent la source en file pour chaque
 *    employé lié, y compris l'ancien employé quand le shift change de main ;
 *  - réconciliation : la commande `google-calendar:sync` rejoue la file puis, à intervalle régulier, remet en file tout ce
 *    qui peut différer (shifts de la fenêtre, congés approuvés, événements orphelins), ce qui rattrape toute écriture
 *    oubliée ou tout changement de congé (le bundle ne reçoit pas d'événement de congé).
 *
 * La file ne garde que la source à réconcilier : l'opération (créer, modifier, supprimer, ne rien faire) se déduit de
 * l'état réel de la source au moment du traitement et de l'empreinte du dernier contenu envoyé. Un traitement répété est
 * donc sans effet de bord.
 */
class CalendarSync
{
    /** Fenêtre glissante synchronisée : de −7 jours à +90 jours. */
    public const WINDOW_PAST_DAYS = 7;

    public const WINDOW_FUTURE_DAYS = 90;

    /** Abandon d'une opération après ce nombre d'essais (délai : 1 min, 2, 4… plafonné à 6 h). */
    public const MAX_ATTEMPTS = 8;

    /** Intervalle entre deux réconciliations complètes d'une liaison. */
    public const RECONCILE_EVERY_HOURS = 6;

    /** @var array<int, string> Jetons d'accès obtenus pendant ce traitement, par liaison. */
    private array $accessTokens = [];

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly CalendarEventBuilder $builder,
        private readonly TimeOffProvider $timeOff,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly ActivityRecorder $activity,
    ) {}

    // -- Mise en file --------------------------------------------------------------------------------------------------

    /**
     * Un shift a été écrit : met sa source en file pour son employé, pour les autres employés indiqués (l'ancien
     * employé, le remplacé…) et pour chaque liaison qui a déjà un événement pour ce shift (même si le shift a disparu).
     */
    public function shiftChanged(int $shiftId, ?int ...$otherUserIds): void
    {
        $userIds = array_values(array_filter([
            Shift::withTrashed()->whereKey($shiftId)->value('user_id'),
            ...$otherUserIds,
        ], fn (mixed $id): bool => $id !== null));

        $connections = CalendarConnection::query()->active()
            ->where(fn (Builder $query) => $query
                ->whereIn('user_id', $userIds)
                ->orWhereHas('links', fn (Builder $links) => $links->where('source_type', CalendarEventLink::SHIFT)->where('source_key', (string) $shiftId)))
            ->get();

        foreach ($connections as $connection) {
            $this->enqueue($connection, CalendarEventLink::SHIFT, (string) $shiftId);
        }
    }

    public function enqueue(CalendarConnection $connection, string $sourceType, string $sourceKey): void
    {
        CalendarSyncQueueItem::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'source_type' => $sourceType, 'source_key' => $sourceKey],
            ['attempts' => 0, 'next_attempt_at' => null, 'last_error' => null],
        );
    }

    /**
     * Remet en file tout ce qui peut différer entre Kintai et l'agenda : shifts et congés de la fenêtre, plus les
     * événements dont la source a disparu.
     *
     * @return int Nombre de sources mises en file.
     */
    public function reconcile(CalendarConnection $connection): int
    {
        $queued = 0;
        $present = [];
        [$from, $to] = $this->window();

        $shifts = Shift::query()->where('user_id', $connection->user_id)
            ->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('id');

        foreach ($shifts as $id) {
            $this->enqueue($connection, CalendarEventLink::SHIFT, (string) $id);
            $present[CalendarEventLink::SHIFT.':'.$id] = true;
            $queued++;
        }

        $user = User::query()->find($connection->user_id);

        foreach ($user === null ? [] : array_keys($this->approvedPeriods($user)) as $uid) {
            $this->enqueue($connection, CalendarEventLink::TIMEOFF, $uid);
            $present[CalendarEventLink::TIMEOFF.':'.$uid] = true;
            $queued++;
        }

        foreach ($connection->links()->get() as $link) {
            if (! isset($present[$link->source_type.':'.$link->source_key])) {
                // Source hors fenêtre, supprimée ou refusée : le traitement supprimera l'événement.
                $this->enqueue($connection, $link->source_type, $link->source_key);
                $queued++;
            }
        }

        $connection->forceFill(['reconciled_at' => now()])->save();

        return $queued;
    }

    public function reconcileIsDue(CalendarConnection $connection): bool
    {
        return $connection->reconciled_at === null || $connection->reconciled_at->lte(now()->subHours(self::RECONCILE_EVERY_HOURS));
    }

    // -- Traitement de la file -----------------------------------------------------------------------------------------

    /**
     * Traite les éléments dus de la file.
     *
     * @return array{processed: int, failed: int, revoked: int}
     */
    public function processQueue(int $limit = 100): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'revoked' => 0];

        $items = CalendarSyncQueueItem::query()->with('connection')
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(max(1, $limit))->get();

        foreach ($items as $item) {
            $connection = $item->connection;

            if (! $connection->isActive()) {
                $item->delete();

                continue;
            }

            try {
                $this->syncSource($connection, $item->source_type, $item->source_key);
                $item->delete();
                $connection->forceFill(['last_sync_at' => now(), 'last_error' => null])->save();
                $stats['processed']++;
            } catch (GoogleCalendarException $e) {
                if ($e->isAuthRevoked()) {
                    $this->markRevoked($connection, $e);
                    $item->delete();
                    $stats['revoked']++;
                } elseif ($e->isRetryable() && $item->attempts + 1 < self::MAX_ATTEMPTS) {
                    $attempts = $item->attempts + 1;
                    $delay = min(6 * 3600, 60 * (2 ** ($attempts - 1)));
                    $item->forceFill(['attempts' => $attempts, 'next_attempt_at' => now()->addSeconds($delay), 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                    $stats['failed']++;
                } else {
                    // Refus définitif (requête invalide) ou essais épuisés : on abandonne cet élément, la réconciliation
                    // suivante le retentera, et l'erreur reste visible sur la page de l'utilisateur.
                    $item->delete();
                    $connection->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                    $stats['failed']++;
                }
            } catch (Throwable $e) {
                // Le détail reste dans les journaux de l'application : la page de l'utilisateur n'affiche qu'un message neutre.
                report($e);
                $item->delete();
                $connection->forceFill(['last_error' => 'Unexpected error while syncing.'])->save();
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Réconcilie une source avec l'agenda : crée, modifie ou supprime l'événement selon l'état réel de la source.
     *
     * @throws GoogleCalendarException
     */
    public function syncSource(CalendarConnection $connection, string $sourceType, string $sourceKey): void
    {
        $link = CalendarEventLink::query()->where('connection_id', $connection->id)
            ->where('source_type', $sourceType)->where('source_key', $sourceKey)->first();
        $built = $this->desiredEvent($connection, $sourceType, $sourceKey);

        if ($built === null) {
            // La source ne doit plus figurer dans l'agenda : rien à faire sans événement, sinon on le supprime.
            if ($link !== null) {
                $token = $this->accessToken($connection);
                $calendarId = $this->ensureCalendar($connection, $token);
                $this->client->deleteEvent($token, $calendarId, $link->external_event_id);
                $link->delete();
            }

            return;
        }

        if ($link !== null && hash_equals($link->content_hash, $built['hash'])) {
            return;
        }

        $token = $this->accessToken($connection);
        $calendarId = $this->ensureCalendar($connection, $token);

        if ($link !== null) {
            try {
                $this->client->patchEvent($token, $calendarId, $link->external_event_id, $built['event']);
                $link->forceFill(['content_hash' => $built['hash'], 'synced_at' => now()])->save();

                return;
            } catch (GoogleCalendarException $e) {
                if (! $e->isGone()) {
                    throw $e;
                }
                // Événement supprimé à la main côté Google : on le recrée plutôt que de laisser un trou dans l'agenda.
            }
        }

        $eventId = $this->client->insertEvent($token, $calendarId, $built['event']);

        CalendarEventLink::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'source_type' => $sourceType, 'source_key' => $sourceKey],
            ['external_event_id' => $eventId, 'content_hash' => $built['hash'], 'synced_at' => now()],
        );
    }

    // -- Liaison / déliaison -------------------------------------------------------------------------------------------

    /**
     * Enregistre une nouvelle liaison (jeton chiffré) et lance la première synchronisation en file. Une liaison
     * existante est remplacée : l'ancien agenda n'est plus celui de Kintai.
     */
    public function connect(int $userId, string $refreshToken, ?string $email, string $nameMode = CalendarConnection::NAME_INSTANCE, ?int $storeId = null): CalendarConnection
    {
        $connection = DB::transaction(function () use ($userId, $refreshToken, $email, $nameMode, $storeId): CalendarConnection {
            CalendarConnection::query()->where('user_id', $userId)->delete();

            return CalendarConnection::query()->create([
                'user_id' => $userId,
                'account_email' => $email,
                'refresh_token' => $refreshToken,
                'name_mode' => $nameMode,
                'store_id' => $nameMode === CalendarConnection::NAME_STORE ? $storeId : null,
            ]);
        });

        $this->reconcile($connection);

        // L'agenda apparaît tout de suite chez Google, même sans shift à venir ; en cas d'échec, la première synchro le créera.
        try {
            $this->ensureCalendarFor($connection);
        } catch (GoogleCalendarException) {
        }

        return $connection->refresh();
    }

    /**
     * Change le nom de l'agenda (entreprise ou magasin) ; renommé chez Google tout de suite, sinon à la prochaine synchro.
     */
    public function rename(CalendarConnection $connection, string $nameMode, ?int $storeId): void
    {
        $connection->forceFill([
            'name_mode' => $nameMode,
            'store_id' => $nameMode === CalendarConnection::NAME_STORE ? $storeId : null,
        ])->save();

        try {
            $this->ensureCalendarFor($connection);
        } catch (GoogleCalendarException) {
            // Google injoignable : le renommage sera repris au prochain événement synchronisé.
        }
    }

    /**
     * Crée l'agenda chez Google s'il n'existe pas encore et le renomme si le nom attendu a changé.
     *
     * @throws GoogleCalendarException
     */
    public function ensureCalendarFor(CalendarConnection $connection): void
    {
        $this->ensureCalendar($connection, $this->accessToken($connection));
    }

    /**
     * Supprime la liaison. Le jeton est révoqué chez Google ; si `$deleteCalendarEvents`, les événements de Kintai sont
     * d'abord retirés de l'agenda. Une panne réseau n'empêche jamais la déconnexion locale.
     */
    public function disconnect(CalendarConnection $connection, bool $deleteCalendarEvents = false): void
    {
        try {
            $refresh = $connection->decryptedRefreshToken();

            if ($deleteCalendarEvents && $connection->isActive() && (string) $connection->calendar_id !== '') {
                $token = $this->client->accessToken($refresh);

                foreach ($connection->links()->get() as $link) {
                    $this->client->deleteEvent($token, (string) $connection->calendar_id, $link->external_event_id);
                }
            }

            $this->client->revoke($refresh);
        } catch (GoogleCalendarException|DecryptException) {
            // Jeton déjà invalide, illisible ou réseau coupé : la liaison locale est supprimée dans tous les cas.
        }

        $connection->delete();
    }

    // -- Internes ------------------------------------------------------------------------------------------------------

    /**
     * @throws GoogleCalendarException
     */
    private function accessToken(CalendarConnection $connection): string
    {
        if (! isset($this->accessTokens[$connection->id])) {
            try {
                $refresh = $connection->decryptedRefreshToken();
            } catch (DecryptException) {
                // Chiffré avec une autre clé (base restaurée ailleurs) : la liaison est inutilisable.
                throw new GoogleCalendarException('The link token is unreadable.', 0, 'invalid_grant');
            }

            $this->accessTokens[$connection->id] = $this->client->accessToken($refresh);
        }

        return $this->accessTokens[$connection->id];
    }

    /**
     * Crée l'agenda « entreprise » au premier passage et le renomme quand le nom attendu a changé.
     *
     * @throws GoogleCalendarException
     */
    private function ensureCalendar(CalendarConnection $connection, string $token): string
    {
        $wanted = $this->calendarName($connection);
        $id = (string) $connection->calendar_id;

        if ($id === '') {
            $id = $this->client->createCalendar($token, $wanted, (string) config('kintai.timezone', 'UTC'));
            $connection->forceFill(['calendar_id' => $id, 'calendar_name' => $wanted])->save();
        } elseif ((string) $connection->calendar_name !== $wanted) {
            $this->client->renameCalendar($token, $id, $wanted);
            $connection->forceFill(['calendar_name' => $wanted])->save();
        }

        return $id;
    }

    /**
     * Nom de l'agenda : celui de l'organisation, ou d'un magasin au choix de l'utilisateur ; « Kintai » à défaut.
     */
    private function calendarName(CalendarConnection $connection): string
    {
        if ($connection->name_mode === CalendarConnection::NAME_STORE && $connection->store_id !== null) {
            $name = trim((string) Store::query()->whereKey($connection->store_id)->value('name'));

            if ($name !== '') {
                return $name;
            }
        }

        $name = trim($this->settings->string('organization.subtitle'));

        return $name !== '' ? $name : 'Kintai';
    }

    /**
     * Événement attendu pour la source, ou null quand elle ne doit pas (ou plus) figurer dans l'agenda.
     *
     * @return array{event: array<string, mixed>, hash: string}|null
     */
    private function desiredEvent(CalendarConnection $connection, string $sourceType, string $sourceKey): ?array
    {
        $user = User::query()->find($connection->user_id);

        if ($user === null) {
            return null;
        }

        if ($sourceType === CalendarEventLink::SHIFT) {
            // Un shift supprimé n'est plus trouvé (SoftDeletes) : l'événement sera supprimé.
            $shift = ctype_digit($sourceKey) ? Shift::query()->with(['store', 'shiftType'])->find((int) $sourceKey) : null;

            if ($shift === null || $shift->user_id !== $user->id || ! $this->inWindow($shift->shift_date->toDateString())) {
                return null;
            }

            return $this->builder->forShift($shift, $user);
        }

        if ($sourceType === CalendarEventLink::TIMEOFF) {
            $approved = $this->approvedPeriods($user)[$sourceKey] ?? null;

            return $approved === null ? null : $this->builder->forTimeOff($approved['period'], $approved['store'], $user);
        }

        return null;
    }

    /**
     * Congés approuvés de l'employé dans la fenêtre, par identifiant de congé : le contrat les donne par magasin, on les
     * lit donc pour chaque magasin de l'employé (un congé vu dans plusieurs magasins n'est gardé qu'une fois).
     *
     * @return array<string, array{period: TimeOffPeriod, store: Store}>
     */
    private function approvedPeriods(User $user): array
    {
        [$from, $to] = $this->window();
        $periods = [];

        foreach ($user->stores()->get() as $store) {
            foreach ($this->timeOff->approvedFor($user->id, $store->id, $from, $to) as $period) {
                $periods[$period->uid] ??= ['period' => $period, 'store' => $store];
            }
        }

        return $periods;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(): array
    {
        $today = CarbonImmutable::now((string) config('kintai.timezone', 'UTC'))->startOfDay();

        return [$today->subDays(self::WINDOW_PAST_DAYS), $today->addDays(self::WINDOW_FUTURE_DAYS)];
    }

    private function inWindow(string $date): bool
    {
        [$from, $to] = $this->window();

        return $date >= $from->toDateString() && $date <= $to->toDateString();
    }

    /**
     * Liaison révoquée : le jeton ne sert plus, l'utilisateur en est averti (une seule fois).
     */
    private function markRevoked(CalendarConnection $connection, GoogleCalendarException $e): void
    {
        $connection->forceFill(['status' => CalendarConnection::STATUS_REVOKED, 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
        $this->activity->record('google_calendar.revoked', $connection);

        try {
            $this->notifier->notify(
                $connection->user_id,
                GoogleCalendarServiceProvider::NOTIFICATION,
                'Kintai no longer has access to your Google Calendar (access revoked or expired). Reconnect your account to resume syncing.',
                [],
                route('google-calendar.profile', [], false),
            );
        } catch (Throwable $notificationError) {
            // La notification est un confort : la liaison est déjà marquée révoquée.
            report($notificationError);
        }
    }
}
