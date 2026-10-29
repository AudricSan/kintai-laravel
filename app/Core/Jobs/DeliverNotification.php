<?php

declare(strict_types=1);

namespace kintai\Core\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use kintai\Core\Services\NotificationService;

/**
 * Envoi d'une notification déjà enregistrée vers ses canaux externes (push mobile, web push, e-mail), hors de la
 * requête qui l'a créée : publier un planning pour trente employés ne fait plus attendre la page le temps de trente
 * e-mails SMTP.
 *
 * La cloche (table notifications) est toujours écrite tout de suite, par NotificationService::notify() ; les choix de
 * canaux (préférences, heures silencieuses) y sont faits aussi, au moment de la notification, et voyagent avec la tâche.
 * Seules des valeurs simples sont sérialisées : la tâche passe telle quelle par la file « deferred » comme par
 * « database ».
 */
final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    /** Un canal en panne n'est pas rejoué : NotificationService::deliver() avale ses erreurs, comme avant la file. */
    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int $referenceId,
        public readonly ?string $link,
        public readonly int $notificationId,
        public readonly bool $push,
        public readonly bool $quiet,
        public readonly bool $email,
    ) {}

    public function handle(): void
    {
        // Après une requête web (file « deferred »), Kintai est démarré. Dans `php artisan queue:work` (file
        // « database »), ses services n'existent qu'une fois démarré, comme pour une requête.
        app(NotificationService::class)->deliver($this);
    }
}
