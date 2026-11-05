<?php

namespace Kintai\Contracts\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'extension : le Core et les bundles y déclarent leurs types de notification.
 *
 * Un bundle s'y enregistre depuis son fournisseur de services, au démarrage :
 *
 *     app(NotificationTypeRegistry::class)->register(
 *         new NotificationType('timeoff_approved', 'Time off approved', 'requests', 'sun', push: true, mail: true, referenceType: 'timeoff'),
 *     );
 *
 * Les préférences, la cloche et l'e-mail sont gérés par le Core.
 */
interface NotificationTypeRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(NotificationType $type): void;

    public function has(string $key): bool;

    public function get(string $key): ?NotificationType;

    /**
     * @return array<string, NotificationType>
     */
    public function all(): array;

    /**
     * Types de la grille des préférences (hors types internes), groupés par catégorie dans l'ordre des catégories ;
     * les catégories vides sont omises. Les types réservés aux Owners n'apparaissent que dans la grille d'un Owner.
     *
     * @return array<string, list<NotificationType>>
     */
    public function grid(?Authenticatable $user = null): array;
}
