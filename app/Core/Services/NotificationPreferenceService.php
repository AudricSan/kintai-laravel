<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Contract\Extension\NotificationType;
use kintai\Core\Repositories\NotificationPreferenceRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Préférences de notification d'un utilisateur : canaux « push » et « email » par type, et heures silencieuses.
 *
 * Seuls les écarts avec les valeurs par défaut du type sont enregistrés (voir NotificationType). La cloche n'est jamais
 * coupée : ni canal in-app ici, ni heures silencieuses pour elle. Les heures silencieuses se lisent dans le fuseau de
 * l'instance (date_default_timezone_set(), config/app.php) ; une plage peut passer minuit (ex. 22:00 → 07:00).
 */
final class NotificationPreferenceService
{
    public const CHANNELS = ['push', 'email'];

    /**
     * Notification de test de l'Owner : elle passe par tous les canaux actifs, sans tenir compte des préférences ni des
     * heures silencieuses (c'est précisément ce qu'on veut vérifier), et n'apparaît donc pas dans la grille.
     */
    public const TEST_TYPE = 'notification_test';

    /** Plage proposée à l'activation des heures silencieuses. */
    public const DEFAULT_QUIET_START = '22:00';
    public const DEFAULT_QUIET_END   = '07:00';

    /** @var array<int, array<string, array<string, bool>>> préférences déjà chargées, par utilisateur */
    private array $cache = [];

    /** @var (callable(): \DateTimeInterface)|null Horloge injectable pour les tests */
    private $clock;

    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $repo,
        private readonly NotificationTypeRegistry $types,
        private readonly UserRepositoryInterface $users,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * Charge en une requête les préférences de ces utilisateurs (envoi groupé, sans N+1).
     *
     * @param list<int> $userIds
     */
    public function preload(array $userIds): void
    {
        $missing = array_values(array_filter(
            array_unique(array_map('intval', $userIds)),
            fn(int $id) => !isset($this->cache[$id]),
        ));
        if ($missing === []) {
            return;
        }
        $found = $this->repo->findByUsers($missing);
        foreach ($missing as $id) {
            $this->cache[$id] = $found[$id] ?? [];
        }
    }

    /** Le canal est-il activé pour ce type ? Sans écart enregistré : valeur par défaut du type (inconnu : push oui, e-mail non). */
    public function isEnabled(int $userId, string $type, string $channel): bool
    {
        $this->preload([$userId]);
        $override = $this->cache[$userId][$type][$channel] ?? null;
        if ($override !== null) {
            return $override;
        }
        return ($this->types->get($type) ?? new NotificationType($type))->defaultFor($channel);
    }

    /**
     * Grille des préférences : types actifs regroupés par catégorie.
     *
     * @return array<string, list<array{type: NotificationType, push: bool, email: bool}>>
     */
    public function grid(int $userId): array
    {
        $grid = [];
        foreach ($this->types->byCategory() as $category => $types) {
            foreach ($types as $type) {
                $grid[$category][] = [
                    'type'  => $type,
                    'push'  => $this->isEnabled($userId, $type->type, 'push'),
                    'email' => $this->isEnabled($userId, $type->type, 'email'),
                ];
            }
        }
        return $grid;
    }

    /**
     * Enregistre la grille : pour chaque type actif et chaque canal, activé si le type figure dans la liste. Un état
     * égal à la valeur par défaut supprime l'écart. Les types qui ne sont pas actifs (bundle désactivé) gardent leurs
     * préférences.
     *
     * @param list<string>      $push  types cochés dans la colonne Push
     * @param list<string>      $email types cochés dans la colonne E-mail
     * @param list<string>|null $shown types que le formulaire affichait. Une case décochée n'a de sens que pour une ligne que
     *                                  l'utilisateur a vue : un type apparu depuis (bundle activé pendant que la page était
     *                                  ouverte) ne doit pas être compté « décoché » et coupé à son insu. Null : tous les types actifs.
     */
    public function saveGrid(int $userId, array $push, array $email, ?array $shown = null): void
    {
        $checked = ['push' => array_flip($push), 'email' => array_flip($email)];
        $seen    = $shown !== null ? array_flip($shown) : null;
        foreach ($this->types->all() as $type) {
            if ($type->type === self::TEST_TYPE || NotificationTypeRegistry::isOwnerAlert($type->type) || ($seen !== null && !isset($seen[$type->type]))) {
                continue;
            }
            foreach (self::CHANNELS as $channel) {
                $this->store($userId, $type, $channel, isset($checked[$channel][$type->type]));
            }
        }
    }

    /**
     * Modifie certaines cases seulement (API) ; les autres ne changent pas. Tout ou rien : à la première entrée
     * invalide, rien n'est enregistré.
     *
     * @param array<string, mixed> $changes type => canal => activé (booléen)
     * @return list<string> entrées invalides (type ou canal inconnu, valeur non booléenne) ; vide si tout est enregistré
     */
    public function saveChanges(int $userId, array $changes): array
    {
        $valid   = [];
        $invalid = [];
        foreach ($changes as $typeKey => $channels) {
            $type = $this->types->get((string) $typeKey);
            if ($type === null || !is_array($channels)) {
                $invalid[] = (string) $typeKey;
                continue;
            }
            foreach ($channels as $channel => $enabled) {
                if (!in_array($channel, self::CHANNELS, true) || !is_bool($enabled)) {
                    $invalid[] = $typeKey . '.' . $channel;
                    continue;
                }
                $valid[] = [$type, $channel, $enabled];
            }
        }
        if ($invalid !== []) {
            return $invalid;
        }
        foreach ($valid as [$type, $channel, $enabled]) {
            $this->store($userId, $type, $channel, $enabled);
        }
        return [];
    }

    /** @return array{enabled: bool, start: string, end: string} Heures proposées par défaut tant que rien n'est réglé. */
    public function quietHours(int $userId): array
    {
        $user  = $this->users->findById($userId) ?? [];
        $start = self::normalizeTime($user['quiet_hours_start'] ?? null);
        $end   = self::normalizeTime($user['quiet_hours_end'] ?? null);
        return [
            'enabled' => $start !== null && $end !== null && $start !== $end,
            'start'   => $start ?? self::DEFAULT_QUIET_START,
            'end'     => $end ?? self::DEFAULT_QUIET_END,
        ];
    }

    /**
     * Enregistre les heures silencieuses ; désactivées, elles sont effacées.
     *
     * @return bool faux si activées avec une heure invalide (format HH:MM) ou un début égal à la fin : rien n'est enregistré
     */
    public function saveQuietHours(int $userId, bool $enabled, ?string $start, ?string $end): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }

        $s = self::normalizeTime($start);
        $e = self::normalizeTime($end);
        if ($enabled && ($s === null || $e === null || $s === $e)) {
            return false;
        }

        $user['quiet_hours_start'] = $enabled ? $s : null;
        $user['quiet_hours_end']   = $enabled ? $e : null;
        $this->users->save($user);
        return true;
    }

    /** L'utilisateur est-il dans ses heures silencieuses à cet instant (par défaut : maintenant) ? */
    public function isQuietNow(int $userId, ?\DateTimeInterface $now = null): bool
    {
        $quiet = $this->quietHours($userId);
        if (!$quiet['enabled']) {
            return false;
        }
        $now ??= $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
        return self::inRange($now->format('H:i'), $quiet['start'], $quiet['end']);
    }

    /** $time ∈ [$start, $end[, la plage pouvant passer minuit. Au format HH:MM, l'ordre alphabétique est l'ordre horaire. */
    public static function inRange(string $time, string $start, string $end): bool
    {
        if ($start === $end) {
            return false;
        }
        return $start < $end
            ? ($time >= $start && $time < $end)
            : ($time >= $start || $time < $end);
    }

    /** HH:MM (ou HH:MM:SS) → HH:MM ; null si invalide. */
    public static function normalizeTime(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($value), $m) !== 1) {
            return null;
        }
        return $m[1] . ':' . $m[2];
    }

    private function store(int $userId, NotificationType $type, string $channel, bool $enabled): void
    {
        $this->preload([$userId]);
        if ($enabled === $type->defaultFor($channel)) {
            $this->repo->reset($userId, $type->type, $channel);
            unset($this->cache[$userId][$type->type][$channel]);
            return;
        }
        $this->repo->set($userId, $type->type, $channel, $enabled);
        $this->cache[$userId][$type->type][$channel] = $enabled;
    }
}
