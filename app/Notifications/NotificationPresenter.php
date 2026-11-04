<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Texte d'une notification dans la langue courante : la base ne garde que des clés de traduction et des
 * paramètres, donc chaque lecteur la lit dans sa langue (et le mail dans celle du destinataire).
 */
final class NotificationPresenter
{
    /**
     * @param  array<string, mixed>  $data  Données stockées (voir KintaiNotification::toDatabase()).
     * @return array{title: string, body: string, icon: string}
     */
    public static function present(string $type, array $data): array
    {
        $definition = app(NotificationTypeRegistry::class)->get($type);
        $key = is_string($data['body'] ?? null) ? $data['body'] : '';
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        foreach ($params as $name => $value) {
            // Les dates sont stockées en "Y-m-d" et mises en forme à l'affichage, dans la langue du lecteur.
            if ($name === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $params[$name] = CarbonImmutable::parse($value)->locale(app()->getLocale())->isoFormat('ddd D MMM YYYY');
            }
        }

        return [
            'title' => $definition !== null ? __($definition->label) : $type,
            'body' => $key === '' ? '' : __($key, array_map(fn ($value): string => is_scalar($value) ? (string) $value : '', $params)),
            'icon' => $definition->icon ?? 'bell',
        ];
    }

    /**
     * @return array{title: string, body: string, icon: string, link: string|null, read: bool}
     */
    public static function forRecord(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return self::present($notification->type, $data) + [
            'link' => NotificationLink::internal(is_string($data['link'] ?? null) ? $data['link'] : null),
            'read' => $notification->read_at !== null,
        ];
    }
}
