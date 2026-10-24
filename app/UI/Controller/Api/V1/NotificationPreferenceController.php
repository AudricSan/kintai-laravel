<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\NotificationPreferenceService;

final class NotificationPreferenceController
{
    public function __construct(
        private readonly NotificationPreferenceService $prefs,
        private readonly UserTargetGuard $guard,
    ) {}

    /**
     * GET /api/v1/users/{user_id}/notification-preferences
     * Grille des types actifs (canaux push et e-mail, la cloche étant toujours active) et heures silencieuses.
     */
    public function show(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        return Response::json($this->payload($userId));
    }

    /**
     * PUT /api/v1/users/{user_id}/notification-preferences
     *
     * Champs facultatifs, chacun enregistré seulement s'il est envoyé :
     *   - `preferences` : { "<type>": { "push": bool, "email": bool } } ; seules les cases citées changent ;
     *   - `quiet_hours` : { "enabled": bool, "start": "HH:MM", "end": "HH:MM" } (début et fin obligatoires si activées).
     */
    public function update(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $data = $request->json() ?? [];

        $changes = $data['preferences'] ?? null;
        if ($changes !== null && !is_array($changes)) {
            throw new ValidationException(['preferences' => [__('validation_notif_prefs_invalid')]]);
        }
        $quiet = $data['quiet_hours'] ?? null;
        if ($quiet !== null && (!is_array($quiet) || !is_bool($quiet['enabled'] ?? null))) {
            throw new ValidationException(['quiet_hours' => [__('validation_quiet_hours_invalid')]]);
        }

        if ($quiet !== null) {
            $saved = $this->prefs->saveQuietHours(
                $userId,
                $quiet['enabled'],
                isset($quiet['start']) ? (string) $quiet['start'] : null,
                isset($quiet['end']) ? (string) $quiet['end'] : null,
            );
            if (!$saved) {
                throw new ValidationException(['quiet_hours' => [__('validation_quiet_hours_invalid')]]);
            }
        }
        if ($changes !== null) {
            $ignored = $this->prefs->saveChanges($userId, $changes);
            if ($ignored !== []) {
                throw new ValidationException(['preferences' => [__('validation_notif_prefs_invalid') . ' (' . implode(', ', $ignored) . ')']]);
            }
        }

        return Response::json($this->payload($userId));
    }

    private function payload(int $userId): array
    {
        $types = [];
        foreach ($this->prefs->grid($userId) as $category => $rows) {
            foreach ($rows as $row) {
                $types[] = [
                    'type'     => $row['type']->type,
                    'category' => $category,
                    'push'     => $row['push'],
                    'email'    => $row['email'],
                ];
            }
        }

        return ['user_id' => $userId, 'preferences' => $types, 'quiet_hours' => $this->prefs->quietHours($userId)];
    }
}
