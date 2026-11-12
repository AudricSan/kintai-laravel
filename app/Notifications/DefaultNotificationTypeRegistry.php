<?php

namespace App\Notifications;

use App\Authorization\Authorizer;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultNotificationTypeRegistry implements NotificationTypeRegistry
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
    public function grid(?Authenticatable $user = null): array
    {
        $grid = [];
        $isOwner = $user instanceof User && app(Authorizer::class)->isOwner($user);

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
