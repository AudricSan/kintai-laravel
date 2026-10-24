<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Extension\Absences;
use kintai\Core\Repositories\CalendarSyncRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\Calendar\CalendarEventBuilder;
use kintai\Core\Services\Calendar\GoogleCalendarClient;
use kintai\Core\Services\Calendar\GoogleCalendarException;
use kintai\Core\Services\Calendar\GoogleCalendarSettings;

/**
 * Synchronisation Kintai → Google Calendar (jamais dans l'autre sens).
 *
 * Deux filets complémentaires :
 *  - temps réel : ShiftChangeNotifier appelle shiftChanged() à chaque écriture d'un shift, ce qui met la source en
 *    file pour l'employé concerné (et pour l'ancien employé quand le shift change de main) ;
 *  - réconciliation : le job `calendar-sync` rejoue la file puis, à intervalle régulier, remet en file tout ce qui
 *    diffère (shifts de la fenêtre, congés approuvés, événements orphelins), ce qui rattrape toute écriture oubliée.
 *
 * La file ne garde que la source à réconcilier : l'opération (créer, modifier, supprimer, ne rien faire) se déduit de
 * l'état réel du shift au moment du traitement et de l'empreinte du dernier contenu envoyé. Un traitement répété est
 * donc sans effet de bord.
 */
final class CalendarSyncService
{
    /** Fenêtre glissante synchronisée : de −7 jours à +90 jours. */
    public const WINDOW_PAST_DAYS   = 7;
    public const WINDOW_FUTURE_DAYS = 90;

    /** Abandon d'une opération après ce nombre d'essais (backoff : 1 min, 2, 4… plafonné à 6 h). */
    public const MAX_ATTEMPTS = 8;

    /** Intervalle entre deux réconciliations complètes d'une liaison. */
    public const RECONCILE_EVERY_SECONDS = 6 * 3600;

    /** @var array<int, string> jetons d'accès obtenus pendant ce traitement, par liaison */
    private array $accessTokens = [];

    /** @var ?\Closure(int): list<array<string, mixed>> lecteur d'absences remplacé en test ; défaut : fournisseurs d'absences des bundles */
    private ?\Closure $absenceReader = null;

    public function __construct(
        private readonly CalendarSyncRepositoryInterface $repo,
        private readonly GoogleCalendarClient $client,
        private readonly GoogleCalendarSettings $settings,
        private readonly SecretBox $secrets,
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $types,
        private readonly StoreRepositoryInterface $stores,
        private readonly UserRepositoryInterface $users,
        private readonly CalendarEventBuilder $builder,
        private readonly AppSettingsService $appSettings,
        private readonly NotificationService $notifications,
        private readonly PublicUrlResolver $publicUrl,
    ) {}

    /**
     * Remplace la lecture des absences (tests). Pas un paramètre du constructeur : le conteneur instancie chaque
     * paramètre typé par une classe, et une Closure ne s'instancie pas.
     *
     * @param \Closure(int): list<array<string, mixed>> $reader
     */
    public function useAbsenceReader(\Closure $reader): void
    {
        $this->absenceReader = $reader;
    }

    // ── Mise en file ────────────────────────────────────────────────────────

    /**
     * Annonce l'écriture d'un shift (appelée par ShiftChangeNotifier) : met en file la source pour chaque employé lié.
     *
     * @param array<string, mixed>      $shift
     * @param array<string, mixed>|null $before
     */
    public function shiftChanged(string $kind, array $shift, ?array $before): void
    {
        $shiftId = (int) ($shift['id'] ?? 0);
        if ($shiftId <= 0) {
            return;
        }
        $userIds = [(int) ($shift['user_id'] ?? 0), (int) ($before['user_id'] ?? 0)];
        foreach ($this->repo->activeConnectionsForUsers($userIds) as $connection) {
            $this->repo->enqueue((int) $connection['id'], 'shift', $shiftId);
        }
    }

    /**
     * Remet en file tout ce qui peut différer entre Kintai et l'agenda : shifts et congés de la fenêtre, plus les
     * événements dont la source a disparu.
     *
     * @return int nombre de sources mises en file
     */
    public function reconcile(array $connection): int
    {
        $connectionId = (int) $connection['id'];
        $userId       = (int) $connection['user_id'];
        $queued       = 0;
        $present      = [];

        foreach ($this->shifts->findByUser($userId) as $shift) {
            if (empty($shift['deleted_at']) && $this->inWindow((string) ($shift['shift_date'] ?? ''))) {
                $this->repo->enqueue($connectionId, 'shift', (int) $shift['id']);
                $present['shift:' . (int) $shift['id']] = true;
                $queued++;
            }
        }
        foreach ($this->approvedAbsences($userId) as $absence) {
            $this->repo->enqueue($connectionId, 'timeoff', (int) $absence['id']);
            $present['timeoff:' . (int) $absence['id']] = true;
            $queued++;
        }
        foreach (['shift', 'timeoff'] as $type) {
            foreach ($this->repo->linksForConnection($connectionId, $type) as $link) {
                if (!isset($present[$type . ':' . (int) $link['source_id']])) {
                    // Source hors fenêtre, supprimée ou refusée : le traitement supprimera l'événement.
                    $this->repo->enqueue($connectionId, $type, (int) $link['source_id']);
                    $queued++;
                }
            }
        }

        $options = self::options($connection);
        $options['reconciled_at'] = time();
        $this->repo->updateConnection($connectionId, ['options' => json_encode($options)]);
        return $queued;
    }

    public function reconcileIsDue(array $connection): bool
    {
        $at = (int) (self::options($connection)['reconciled_at'] ?? 0);
        return time() - $at >= self::RECONCILE_EVERY_SECONDS;
    }

    // ── Traitement de la file ───────────────────────────────────────────────

    /**
     * Traite les éléments dus de la file.
     *
     * @return array{processed: int, failed: int, revoked: int}
     */
    public function processQueue(int $limit = 100): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'revoked' => 0];
        foreach ($this->repo->dueQueue($limit) as $item) {
            $connection = $this->repo->findConnection((int) $item['connection_id']);
            if ($connection === null || ($connection['status'] ?? '') !== 'active') {
                $this->repo->removeFromQueue((int) $item['id']);
                continue;
            }

            try {
                $this->syncSource($connection, (string) $item['source_type'], (int) $item['source_id']);
                $this->repo->removeFromQueue((int) $item['id']);
                $this->repo->updateConnection((int) $connection['id'], ['last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
                $stats['processed']++;
            } catch (GoogleCalendarException $e) {
                if ($e->isAuthRevoked()) {
                    $this->markRevoked($connection, $e);
                    $this->repo->removeFromQueue((int) $item['id']);
                    $stats['revoked']++;
                } elseif ($e->isRetryable() && (int) $item['attempts'] + 1 < self::MAX_ATTEMPTS) {
                    $attempts = (int) $item['attempts'] + 1;
                    $delay    = min(6 * 3600, 60 * (2 ** ($attempts - 1)));
                    $this->repo->postponeQueueItem((int) $item['id'], $attempts, date('Y-m-d H:i:s', time() + $delay), $e->getMessage());
                    $stats['failed']++;
                } else {
                    // Refus définitif (requête invalide) ou essais épuisés : on abandonne cet élément, la
                    // réconciliation suivante le retentera, et l'erreur reste visible dans l'onglet Agendas.
                    $this->repo->removeFromQueue((int) $item['id']);
                    $this->repo->updateConnection((int) $connection['id'], ['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $this->repo->removeFromQueue((int) $item['id']);
                $this->repo->updateConnection((int) $connection['id'], ['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /**
     * Réconcilie une source avec l'agenda : crée, modifie ou supprime l'événement selon l'état réel de la source.
     *
     * @param array<string, mixed> $connection
     * @throws GoogleCalendarException
     */
    public function syncSource(array $connection, string $sourceType, int $sourceId): void
    {
        $connectionId = (int) $connection['id'];
        $token        = $this->accessToken($connection);
        $calendarId   = $this->ensureCalendar($connection, $token);
        $link         = $this->repo->findLink($connectionId, $sourceType, $sourceId);
        $built        = $this->desiredEvent($connection, $sourceType, $sourceId);

        if ($built === null) {
            if ($link !== null) {
                $this->client->deleteEvent($token, $calendarId, (string) $link['external_event_id']);
                $this->repo->deleteLink($connectionId, $sourceType, $sourceId);
            }
            return;
        }

        if ($link !== null && hash_equals((string) $link['content_hash'], $built['hash'])) {
            return;
        }

        if ($link !== null) {
            try {
                $this->client->patchEvent($token, $calendarId, (string) $link['external_event_id'], $built['event']);
                $this->repo->saveLink($connectionId, $sourceType, $sourceId, (string) $link['external_event_id'], $built['hash']);
                return;
            } catch (GoogleCalendarException $e) {
                if (!$e->isGone()) {
                    throw $e;
                }
                // Événement supprimé à la main côté Google : on le recrée plutôt que de laisser un trou dans l'agenda.
            }
        }

        $eventId = $this->client->insertEvent($token, $calendarId, $built['event']);
        $this->repo->saveLink($connectionId, $sourceType, $sourceId, $eventId, $built['hash']);
    }

    // ── Connexion / déconnexion ─────────────────────────────────────────────

    /**
     * Enregistre une nouvelle liaison (jeton chiffré) et lance la première synchronisation en file.
     *
     * @param array<string, mixed> $options
     */
    public function connect(int $userId, string $refreshToken, ?string $email, array $options = []): array
    {
        $connection = $this->repo->saveConnection($userId, [
            'account_email' => $email,
            'refresh_token' => $this->secrets->encrypt($refreshToken),
            'options'       => json_encode($options + ['calendar_name_mode' => 'instance']),
        ]);
        $this->reconcile($connection);
        // L'agenda apparaît tout de suite chez Google, même sans shift à venir ; en cas d'échec, la première synchro le créera.
        try {
            $this->ensureCalendarFor($connection);
        } catch (\Throwable) {
        }
        return $this->repo->findConnection((int) $connection['id']) ?? $connection;
    }

    /**
     * Crée l'agenda chez Google s'il n'existe pas encore et le renomme si le nom attendu a changé.
     *
     * @param array<string, mixed> $connection
     * @throws GoogleCalendarException
     */
    public function ensureCalendarFor(array $connection): void
    {
        $this->ensureCalendar($connection, $this->accessToken($connection));
    }

    /**
     * Supprime la liaison. Le jeton est révoqué chez Google ; si $deleteCalendarEvents, les événements de Kintai sont
     * d'abord retirés de l'agenda. Une panne réseau n'empêche jamais la déconnexion locale.
     */
    public function disconnect(int $userId, bool $deleteCalendarEvents = false): void
    {
        $connection = $this->repo->findConnectionByUser($userId);
        if ($connection === null) {
            return;
        }
        $refresh = $this->secrets->decrypt((string) $connection['refresh_token']);
        try {
            if ($refresh !== null && $refresh !== '') {
                if ($deleteCalendarEvents && ($connection['status'] ?? '') === 'active' && (string) $connection['calendar_id'] !== '') {
                    $token = $this->client->accessToken($refresh);
                    foreach (['shift', 'timeoff'] as $type) {
                        foreach ($this->repo->linksForConnection((int) $connection['id'], $type) as $link) {
                            $this->client->deleteEvent($token, (string) $connection['calendar_id'], (string) $link['external_event_id']);
                        }
                    }
                }
                $this->client->revoke($refresh);
            }
        } catch (\Throwable) {
            // Jeton déjà invalide ou réseau coupé : la liaison locale est supprimée dans tous les cas.
        }
        $this->repo->deleteConnection((int) $connection['id']);
    }

    // ── Internes ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $connection @throws GoogleCalendarException */
    private function accessToken(array $connection): string
    {
        $id = (int) $connection['id'];
        if (!isset($this->accessTokens[$id])) {
            $refresh = $this->secrets->decrypt((string) $connection['refresh_token']);
            if ($refresh === null || $refresh === '') {
                // Chiffré par une autre instance (base restaurée ailleurs) : la liaison est inutilisable.
                throw new GoogleCalendarException('Jeton de liaison illisible.', 0, 'invalid_grant');
            }
            $this->accessTokens[$id] = $this->client->accessToken($refresh);
        }
        return $this->accessTokens[$id];
    }

    /** Crée l'agenda « entreprise » au premier passage et le renomme quand le nom attendu a changé. */
    private function ensureCalendar(array &$connection, string $token): string
    {
        $wanted = $this->calendarName($connection);
        $id     = (string) ($connection['calendar_id'] ?? '');

        if ($id === '') {
            $id = $this->client->createCalendar($token, $wanted, $this->defaultTimeZone($connection));
            $this->repo->updateConnection((int) $connection['id'], ['calendar_id' => $id, 'calendar_name' => $wanted]);
            $connection['calendar_id']   = $id;
            $connection['calendar_name'] = $wanted;
        } elseif ((string) ($connection['calendar_name'] ?? '') !== $wanted) {
            $this->client->renameCalendar($token, $id, $wanted);
            $this->repo->updateConnection((int) $connection['id'], ['calendar_name' => $wanted]);
            $connection['calendar_name'] = $wanted;
        }
        return $id;
    }

    /** Nom de l'agenda : celui de l'entreprise, ou d'un magasin au choix de l'utilisateur ; « Kintai » à défaut. */
    private function calendarName(array $connection): string
    {
        $options = self::options($connection);
        if (($options['calendar_name_mode'] ?? 'instance') === 'store' && (int) ($options['store_id'] ?? 0) > 0) {
            $store = $this->stores->findById((int) $options['store_id']);
            if ($store !== null && trim((string) $store['name']) !== '') {
                return trim((string) $store['name']);
            }
        }
        $name = trim($this->appSettings->subtitle());
        return $name !== '' ? $name : 'Kintai';
    }

    private function defaultTimeZone(array $connection): string
    {
        foreach ($this->shifts->findByUser((int) $connection['user_id']) as $shift) {
            $store = $this->stores->findById((int) ($shift['store_id'] ?? 0));
            if ($store !== null && (string) ($store['timezone'] ?? '') !== '') {
                return StoreTimezone::resolve((string) $store['timezone']);
            }
        }
        return date_default_timezone_get();
    }

    /**
     * Événement attendu pour la source, ou null quand elle ne doit pas (ou plus) figurer dans l'agenda.
     *
     * @return array{event: array<string, mixed>, hash: string}|null
     */
    private function desiredEvent(array $connection, string $sourceType, int $sourceId): ?array
    {
        $userId = (int) $connection['user_id'];
        $user   = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }

        if ($sourceType === 'shift') {
            $shift = $this->shifts->findById($sourceId);
            if ($shift === null || !empty($shift['deleted_at']) || (int) ($shift['user_id'] ?? 0) !== $userId
                || !$this->inWindow((string) ($shift['shift_date'] ?? ''))) {
                return null;
            }
            $store = $this->stores->findById((int) $shift['store_id']) ?? [];
            $type  = (int) ($shift['shift_type_id'] ?? 0) > 0 ? $this->types->findById((int) $shift['shift_type_id']) : null;
            return $this->builder->forShift($shift, $store, $type, $user, $this->publicUrl->resolve());
        }

        if ($sourceType === 'timeoff') {
            foreach ($this->approvedAbsences($userId) as $absence) {
                if ((int) $absence['id'] === $sourceId) {
                    $store = $this->stores->findById((int) ($absence['store_id'] ?? 0)) ?? [];
                    return $this->builder->forTimeoff($absence, $store, $user);
                }
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> congés approuvés dont la période touche la fenêtre */
    private function approvedAbsences(int $userId): array
    {
        $rows = $this->absenceReader !== null ? ($this->absenceReader)($userId) : Absences::current()->forUser($userId);
        $from = date('Y-m-d', strtotime('-' . self::WINDOW_PAST_DAYS . ' days'));
        $to   = date('Y-m-d', strtotime('+' . self::WINDOW_FUTURE_DAYS . ' days'));
        return array_values(array_filter($rows, fn(array $a) => ($a['status'] ?? '') === 'approved'
            && (string) ($a['end_date'] ?? '') >= $from && (string) ($a['start_date'] ?? '') <= $to));
    }

    private function inWindow(string $date): bool
    {
        return $date >= date('Y-m-d', strtotime('-' . self::WINDOW_PAST_DAYS . ' days'))
            && $date <= date('Y-m-d', strtotime('+' . self::WINDOW_FUTURE_DAYS . ' days'));
    }

    /** Liaison révoquée : le jeton ne sert plus, l'utilisateur en est averti (une seule fois). */
    private function markRevoked(array $connection, GoogleCalendarException $e): void
    {
        $this->repo->updateConnection((int) $connection['id'], ['status' => 'revoked', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
        try {
            $this->notifications->notify(
                (int) $connection['user_id'],
                'calendar_sync_error',
                'notif_calendar_sync_error_body',
                [],
                null,
                '/profile?tab=ical',
            );
        } catch (\Throwable) {
            // La notification est un confort : la liaison est déjà marquée révoquée.
        }
    }

    /** @return array<string, mixed> */
    private static function options(array $connection): array
    {
        $decoded = json_decode((string) ($connection['options'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}
