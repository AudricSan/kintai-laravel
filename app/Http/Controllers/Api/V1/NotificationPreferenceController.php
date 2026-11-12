<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\SaveNotificationPreferences;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;

/**
 * Préférences de notification : par type et par canal (`push`, `mail`), plus les heures silencieuses.
 */
class NotificationPreferenceController extends ApiController
{
    public function show(Request $request, int $user): JsonResponse
    {
        return $this->payload($this->targetUser($request, $user, 'employees.update'));
    }

    /**
     * `preferences` : type => {push?: bool, mail?: bool}, seuls les types et canaux cités changent ; `quiet_hours` :
     * {enabled: bool, start?: "H:i", end?: "H:i"}.
     */
    public function update(Request $request, int $user, SaveNotificationPreferences $save, NotificationPreferences $preferences, NotificationTypeRegistry $types): JsonResponse
    {
        $target = $this->targetUser($request, $user, 'employees.update');

        $data = $request->validate([
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.push' => ['boolean'],
            'preferences.*.mail' => ['boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.enabled' => ['required_with:quiet_hours', 'boolean'],
            'quiet_hours.start' => ['nullable', 'string', 'max:8'],
            'quiet_hours.end' => ['nullable', 'string', 'max:8'],
        ]);

        $matrix = $preferences->matrix($target);

        foreach ($data['preferences'] ?? [] as $type => $channels) {
            if (! isset($matrix[$type])) {
                throw ValidationException::withMessages(['preferences.'.$type => __('This notification type is unknown.')]);
            }

            foreach (NotificationType::CHANNELS as $channel) {
                if (array_key_exists($channel, $channels)) {
                    $matrix[$type][$channel] = (bool) $channels[$channel];
                }
            }
        }

        $checked = array_map(fn (array $row): array => array_keys(array_filter($row)), $matrix);
        $quiet = $preferences->quietHours($target);
        $incoming = $data['quiet_hours'] ?? null;

        $save->handle(
            $target,
            $checked,
            $incoming === null ? $quiet['enabled'] : (bool) $incoming['enabled'],
            $incoming['start'] ?? $quiet['start'],
            $incoming['end'] ?? $quiet['end'],
        );

        return $this->payload($target);
    }

    private function payload(User $user): JsonResponse
    {
        $preferences = app(NotificationPreferences::class);
        $matrix = $preferences->matrix($user);
        $list = [];

        foreach (app(NotificationTypeRegistry::class)->grid($user) as $category => $types) {
            foreach ($types as $type) {
                $list[] = [
                    'type' => $type->key,
                    'category' => $category,
                    'label' => __($type->label),
                    'push' => $matrix[$type->key][NotificationType::CHANNEL_PUSH],
                    'mail' => $matrix[$type->key][NotificationType::CHANNEL_MAIL],
                ];
            }
        }

        return response()->json(['data' => [
            'user_id' => $user->id,
            'preferences' => $list,
            'quiet_hours' => $preferences->quietHours($user),
        ]]);
    }
}
