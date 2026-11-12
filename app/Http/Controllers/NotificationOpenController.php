<?php

namespace App\Http\Controllers;

use App\Notifications\NotificationLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ouvre une notification : la marque lue, puis redirige vers son lien, seulement s'il est interne
 * (jamais de redirection ouverte), sinon vers la liste des notifications.
 */
class NotificationOpenController extends Controller
{
    public function __invoke(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        $record->markAsRead();

        $data = $record->data;
        $link = NotificationLink::internal(is_string($data['link'] ?? null) ? $data['link'] : null);

        return redirect($link ?? route('notifications.index'));
    }
}
