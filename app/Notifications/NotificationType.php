<?php

namespace App\Notifications;

use InvalidArgumentException;

/**
 * Un type de notification. Objet valeur du contrat Core / bundles : un bundle déclare ses types dans
 * `NotificationTypeRegistry`, le Core s'occupe de la cloche, des préférences et des canaux.
 */
final class NotificationType
{
    /** Canaux réglables par l'utilisateur (la cloche, canal `database`, est toujours active). */
    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_MAIL = 'mail';

    public const CHANNELS = [self::CHANNEL_PUSH, self::CHANNEL_MAIL];

    /** Catégories, dans l'ordre d'affichage des préférences. */
    public const CATEGORIES = ['planning', 'requests', 'messages', 'reports', 'system'];

    /** Clés de traduction des catégories. */
    public const CATEGORY_LABELS = [
        'planning' => 'Planning',
        'requests' => 'Requests',
        'messages' => 'Messages',
        'reports' => 'Reports',
        'system' => 'System',
    ];

    /**
     * @param  string  $key  Identifiant stable : minuscules, chiffres et « _ » (2 à 61 caractères), ex. « shift_assigned ».
     * @param  string  $label  Clé de traduction du libellé (titre de la notification et ligne des préférences).
     * @param  string  $category  Une des `CATEGORIES`.
     * @param  string  $icon  Nom d'icône Flux.
     * @param  bool  $push  Canal push activé par défaut.
     * @param  bool  $mail  Canal e-mail activé par défaut.
     * @param  string|null  $referenceType  Nature de l'objet désigné par `reference_id` (pour « lu » automatique).
     * @param  bool  $internal  Type technique : absent de la grille des préférences, canaux par défaut toujours appliqués.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $category = 'system',
        public readonly string $icon = 'bell',
        public readonly bool $push = false,
        public readonly bool $mail = false,
        public readonly ?string $referenceType = null,
        public readonly bool $internal = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,60}$/', $key) !== 1) {
            throw new InvalidArgumentException("Notification type [{$key}] is invalid (lowercase letters, digits and underscores).");
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Notification type [{$key}] has an unknown category [{$category}].");
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/', $icon) !== 1) {
            throw new InvalidArgumentException("Notification type [{$key}] has an invalid icon [{$icon}].");
        }

        if ($referenceType !== null && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $referenceType) !== 1) {
            throw new InvalidArgumentException("Notification type [{$key}] has an invalid reference type [{$referenceType}].");
        }
    }

    /**
     * Valeur par défaut d'un canal réglable.
     */
    public function defaultFor(string $channel): bool
    {
        return match ($channel) {
            self::CHANNEL_PUSH => $this->push,
            self::CHANNEL_MAIL => $this->mail,
            default => false,
        };
    }
}
