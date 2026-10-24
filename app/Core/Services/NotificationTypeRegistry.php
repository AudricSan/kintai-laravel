<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Contract\Extension\NotificationType;
use kintai\Core\Extension\ExtensionRegistry;

/**
 * Registre unique des types de notification : ceux du Core, puis ceux déclarés par les bundles actifs
 * (Bundle::notificationTypes(), ancien format ou format enrichi). Il porte pour chaque type l'icône, la catégorie des
 * préférences, les canaux activés par défaut et le type d'objet désigné (« lu » automatique).
 *
 * Un type du Core ne peut pas être redéfini par un bundle. Les notifications déjà reçues d'un bundle désactivé
 * restent affichables : leur type est alors inconnu du registre et les appelants retombent sur les valeurs par défaut
 * (voir NotificationType::fromDeclaration() pour l'ancien format).
 */
final class NotificationTypeRegistry
{
    /** @var array<string, NotificationType>|null */
    private ?array $types = null;

    public function __construct(private readonly ExtensionRegistry $extensions) {}

    /** @return array<string, NotificationType> */
    public function all(): array
    {
        return $this->types ??= self::coreTypes() + $this->extensions->notificationTypes();
    }

    /** Alerte système adressée aux Owners (SystemNotificationService) : hors de la grille des préférences. */
    public static function isOwnerAlert(string $type): bool
    {
        return isset(SystemNotificationService::REFERENCE_IDS[$type]);
    }

    public function get(string $type): ?NotificationType
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * Types regroupés par catégorie, dans l'ordre d'affichage des préférences (NotificationType::CATEGORIES).
     *
     * @return array<string, list<NotificationType>>
     */
    public function byCategory(): array
    {
        $grouped = array_fill_keys(NotificationType::CATEGORIES, []);
        foreach ($this->all() as $type) {
            // La notification de test et les alertes système des Owners ne sont pas des choix de l'utilisateur.
            if ($type->type === NotificationPreferenceService::TEST_TYPE || self::isOwnerAlert($type->type)) {
                continue;
            }
            $grouped[$type->category][] = $type;
        }
        return array_filter($grouped);
    }

    /**
     * Types propres au Core. E-mail par défaut seulement pour les décisions qui touchent le planning de l'employé.
     *
     * @return array<string, NotificationType>
     */
    private static function coreTypes(): array
    {
        return [
            'shift_assigned' => new NotificationType('shift_assigned', '📅', 'planning', true, true, 'shift'),
            'shift_updated'  => new NotificationType('shift_updated', '🔄', 'planning', true, false, 'shift'),
            // Le shift n'existe plus : la notification désigne le JOUR (voir shift_day_reference()), vu dans le planning.
            'shift_deleted'  => new NotificationType('shift_deleted', '🗑', 'planning', true, true, 'shift_day'),
            // Liaison Google Calendar tombée (accès retiré, jeton expiré) : seul moyen de prévenir l'employé que son agenda ne suit plus.
            'calendar_sync_error' => new NotificationType('calendar_sync_error', '📆', 'planning', true, false),
            // Notifications système, Owners uniquement (SystemNotificationService) : push par défaut, pas d'e-mail.
            'core_update_available' => new NotificationType('core_update_available', '⬆️', 'system', true, false, 'system'),
            'migration_pending' => new NotificationType('migration_pending', '🗄', 'system', true, false, 'system'),
            'bundle_update_available' => new NotificationType('bundle_update_available', '🧩', 'system', true, false, 'system'),
            'backup_failed' => new NotificationType('backup_failed', '⚠️', 'system', true, false, 'system'),
            'backup_stale' => new NotificationType('backup_stale', '💾', 'system', true, false, 'system'),
            'channel_broken' => new NotificationType('channel_broken', '📡', 'system', true, false, 'system'),
            'license_quota' => new NotificationType('license_quota', '🔑', 'system', true, false, 'system'),
            // Notification de test de l'Owner (paramètres des notifications) : ne figure pas dans les préférences.
            NotificationPreferenceService::TEST_TYPE => new NotificationType(NotificationPreferenceService::TEST_TYPE, '🔔', 'system', true, true),
        ];
    }
}
