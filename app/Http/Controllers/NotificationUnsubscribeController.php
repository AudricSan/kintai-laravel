<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationType;
use App\Notifications\NotificationTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Désabonnement d'un type d'e-mail par le lien signé des messages (canal e-mail seulement ; la cloche reste).
 * Sans connexion : la signature du lien (user + type) fait foi. Le GET demande confirmation ; le POST désactive,
 * y compris pour le « one-click » des clients de messagerie.
 */
class NotificationUnsubscribeController extends Controller
{
    public function __construct(
        private readonly NotificationTypeRegistry $types,
        private readonly NotificationPreferences $preferences,
    ) {}

    public function show(Request $request): Response
    {
        return $this->render($request, done: false);
    }

    public function confirm(Request $request): Response
    {
        [$user, $type] = $this->resolve($request);

        if ($user !== null && $type !== null) {
            $this->preferences->set($user, $type->key, NotificationType::CHANNEL_MAIL, false);
        }

        return $this->render($request, done: true);
    }

    private function render(Request $request, bool $done): Response
    {
        [$user, $type] = $this->resolve($request);

        if ($user === null || $type === null) {
            return response()->view('notifications.unsubscribe', ['state' => 'invalid', 'type' => null, 'action' => null], 403);
        }

        app()->setLocale($user->preferredLocale());

        return response()->view('notifications.unsubscribe', [
            'state' => $done ? 'done' : 'confirm',
            'type' => __($type->label),
            'action' => $request->fullUrl(),
        ]);
    }

    /**
     * @return array{0: User|null, 1: NotificationType|null}
     */
    private function resolve(Request $request): array
    {
        if (! $request->hasValidSignature()) {
            return [null, null];
        }

        $user = User::query()->find((int) $request->query('user'));
        $type = $this->types->get((string) $request->query('type'));

        if ($user === null || $type === null || $type->internal) {
            return [null, null];
        }

        return [$user, $type];
    }
}
