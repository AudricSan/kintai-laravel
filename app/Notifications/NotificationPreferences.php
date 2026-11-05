<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Préférences de notification : par type et par canal (push, e-mail), plus les heures silencieuses.
 * Seuls les écarts avec le défaut du type sont stockés.
 */
class NotificationPreferences
{
    public const DEFAULT_QUIET_START = '22:00';

    public const DEFAULT_QUIET_END = '07:00';

    public function __construct(private readonly NotificationTypeRegistry $types) {}

    /**
     * Le canal est-il actif pour cet utilisateur ? Un type interne applique toujours ses défauts ; un type
     * inconnu (bundle désactivé depuis) n'envoie rien par les canaux externes.
     */
    public function isEnabled(User $user, string $type, string $channel): bool
    {
        $definition = $this->types->get($type);

        if ($definition === null) {
            return false;
        }

        if ($definition->internal) {
            return $definition->defaultFor($channel);
        }

        $override = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('channel', $channel)
            ->value('enabled');

        return $override === null ? $definition->defaultFor($channel) : (bool) $override;
    }

    /**
     * Enregistre un choix : une ligne seulement s'il diffère du défaut du type. Type interne, type inconnu
     * et canal inconnu sont ignorés.
     */
    public function set(User $user, string $type, string $channel, bool $enabled): void
    {
        $definition = $this->types->get($type);

        if ($definition === null || $definition->internal || ! in_array($channel, NotificationType::CHANNELS, true)) {
            return;
        }

        $match = ['user_id' => $user->id, 'type' => $type, 'channel' => $channel];

        if ($enabled === $definition->defaultFor($channel)) {
            NotificationPreference::query()->where($match)->delete();

            return;
        }

        NotificationPreference::query()->updateOrCreate($match, ['enabled' => $enabled]);
    }

    /**
     * Grille complète de l'utilisateur : type => canal => actif, pour les types non internes qui concernent l'utilisateur.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(User $user): array
    {
        $overrides = NotificationPreference::query()->where('user_id', $user->id)->get()
            ->mapWithKeys(fn (NotificationPreference $row): array => [$row->type.'|'.$row->channel => $row->enabled]);

        $matrix = [];
        foreach ($this->types->grid($user) as $types) {
            foreach ($types as $type) {
                foreach (NotificationType::CHANNELS as $channel) {
                    $matrix[$type->key][$channel] = (bool) ($overrides[$type->key.'|'.$channel] ?? $type->defaultFor($channel));
                }
            }
        }

        return $matrix;
    }

    /**
     * Heures silencieuses de l'utilisateur ; les heures par défaut sont proposées tant que rien n'est réglé.
     *
     * @return array{enabled: bool, start: string, end: string}
     */
    public function quietHours(User $user): array
    {
        $start = $user->quiet_hours_start;
        $end = $user->quiet_hours_end;

        return [
            'enabled' => $start !== null && $end !== null && $start !== $end,
            'start' => $start ?? self::DEFAULT_QUIET_START,
            'end' => $end ?? self::DEFAULT_QUIET_END,
        ];
    }

    /**
     * L'utilisateur est-il dans ses heures silencieuses (heure locale de l'instance) ? Le push partira alors en
     * silencieux ; l'e-mail n'est jamais retenu.
     */
    public function isQuietNow(User $user, ?CarbonInterface $now = null): bool
    {
        $quiet = $this->quietHours($user);

        if (! $quiet['enabled']) {
            return false;
        }

        $time = ($now ?? Date::now())->copy()->setTimezone((string) config('kintai.timezone', 'UTC'))->format('H:i');

        return self::inRange($time, $quiet['start'], $quiet['end']);
    }

    /**
     * `$time` dans [`$start`, `$end`[, la plage pouvant passer minuit (au format H:i, l'ordre alphabétique est l'ordre horaire).
     */
    public static function inRange(string $time, string $start, string $end): bool
    {
        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($time >= $start && $time < $end)
            : ($time >= $start || $time < $end);
    }

    /**
     * "H:i" valide (00:00 à 23:59), ou null.
     */
    public static function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($value), $m) !== 1) {
            return null;
        }

        return $m[1].':'.$m[2];
    }
}
