<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveNotificationPreferences
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * Enregistre la grille et les heures silencieuses. Les types et canaux inconnus (ou internes) sont ignorés ;
     * un type ou canal absent de `$channels` est considéré désactivé (case décochée).
     *
     * @param  array<string, mixed>  $channels  type => liste des canaux cochés
     * @param  string|null  $quietStart  "H:i"
     * @param  string|null  $quietEnd  "H:i"
     *
     * @throws ValidationException Heures silencieuses invalides (rien n'est enregistré).
     */
    public function handle(User $user, array $channels, bool $quietEnabled, ?string $quietStart, ?string $quietEnd): void
    {
        $start = NotificationPreferences::normalizeTime($quietStart);
        $end = NotificationPreferences::normalizeTime($quietEnd);

        if ($quietEnabled && ($start === null || $end === null)) {
            throw ValidationException::withMessages(['quietStart' => __('Enter valid quiet hours (HH:MM).')]);
        }

        if ($quietEnabled && $start === $end) {
            throw ValidationException::withMessages(['quietEnd' => __('Quiet hours must start and end at different times.')]);
        }

        DB::transaction(function () use ($user, $channels, $quietEnabled, $start, $end): void {
            foreach ($this->preferences->matrix($user) as $type => $current) {
                $checked = $channels[$type] ?? [];
                $checked = is_array($checked) ? $checked : [];

                foreach (NotificationType::CHANNELS as $channel) {
                    $this->preferences->set($user, $type, $channel, in_array($channel, $checked, true));
                }
            }

            $user->forceFill([
                'quiet_hours_start' => $quietEnabled ? $start : null,
                'quiet_hours_end' => $quietEnabled ? $end : null,
            ])->save();
        });
    }
}
