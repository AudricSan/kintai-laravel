<?php

namespace App\Notifications;

use App\Authorization\Authorizer;
use App\Models\User;
use InvalidArgumentException;

/**
 * Point d'extension : le Core et les bundles y déclarent leurs types de notification.
 *
 * Un bundle s'y enregistre depuis son fournisseur de services, au démarrage :
 *
 *     app(NotificationTypeRegistry::class)->register(
 *         new NotificationType('timeoff_approved', 'Time off approved', 'requests', 'sun', push: true, mail: true, referenceType: 'timeoff'),
 *     );
 *
 * Puis il envoie `KintaiNotification::make('timeoff_approved', 'notif_timeoff_approved_body', [...], $link, $referenceId)`
 * à l'utilisateur ; les préférences, la cloche et l'e-mail sont gérés par le Core.
 */
class NotificationTypeRegistry
{
    /** @var array<string, NotificationType> */
    private array $types = [];

    public function register(NotificationType $type): void
    {
        if (isset($this->types[$type->key])) {
            throw new InvalidArgumentException("Notification type [{$type->key}] is already registered.");
        }

        $this->types[$type->key] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): ?NotificationType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * @return array<string, NotificationType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Types de la grille des préférences (hors types internes), groupés par catégorie dans l'ordre des catégories ;
     * les catégories vides sont omises. Les types réservés aux Owners n'apparaissent que dans la grille d'un Owner.
     *
     * @return array<string, list<NotificationType>>
     */
    public function grid(?User $user = null): array
    {
        $grid = [];
        $isOwner = $user !== null && app(Authorizer::class)->isOwner($user);

        foreach (NotificationType::CATEGORIES as $category) {
            $types = array_values(array_filter(
                $this->types,
                fn (NotificationType $type): bool => $type->category === $category && ! $type->internal && ($isOwner || ! $type->ownerOnly),
            ));

            if ($types !== []) {
                $grid[$category] = $types;
            }
        }

        return $grid;
    }
}
