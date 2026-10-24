<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Type de notification déclaré par un bundle (point d'extension `Bundle::notificationTypes()`, format enrichi du
 * contrat 1.23 ; l'ancien format `type => icône` reste accepté).
 *
 * Le libellé du type est la traduction « notif_{type} » (fichiers lang/ du bundle). La catégorie regroupe les types
 * dans les préférences de l'utilisateur ; push/email sont les valeurs par défaut de ces préférences, tant que
 * l'utilisateur ne les a pas changées (la cloche, elle, reçoit toujours tout). referenceType nomme l'objet désigné
 * par l'identifiant passé à notify() (ex. « timeoff » pour l'id d'une demande de congé) : visiter cet objet marque
 * ses notifications comme lues.
 */
final class NotificationType
{
    /** Catégories reconnues, dans l'ordre d'affichage des préférences. */
    public const CATEGORIES = ['planning', 'requests', 'messages', 'reports', 'system'];

    /**
     * @param string      $type          Identifiant, ex. « timeoff_approved » (minuscules, chiffres, _)
     * @param string      $icon          Icône (un emoji, 8 caractères au plus)
     * @param string      $category      Une des CATEGORIES
     * @param bool        $push          Notification push activée par défaut
     * @param bool        $email         E-mail activé par défaut
     * @param string|null $referenceType Objet désigné par reference_id (minuscules, chiffres, _), null si aucun
     */
    public function __construct(
        public readonly string $type,
        public readonly string $icon = '•',
        public readonly string $category = 'system',
        public readonly bool $push = true,
        public readonly bool $email = false,
        public readonly ?string $referenceType = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,60}$/', $type) !== 1) {
            throw new \InvalidArgumentException("NotificationType : type invalide « {$type} » (minuscules, chiffres, _).");
        }
        if (mb_strlen($icon) > 8) {
            throw new \InvalidArgumentException("NotificationType : icône trop longue pour « {$type} » (8 caractères au plus).");
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("NotificationType : catégorie inconnue « {$category} » pour « {$type} ».");
        }
        if ($referenceType !== null && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $referenceType) !== 1) {
            throw new \InvalidArgumentException("NotificationType : reference_type invalide « {$referenceType} » pour « {$type} ».");
        }
    }

    /**
     * Construit un type depuis ce qu'un bundle déclare dans notificationTypes() : l'ancien format (une icône,
     * catégorie « system », push activé, e-mail désactivé) ou le format enrichi
     * ['icon', 'category', 'channels' => ['push' => bool, 'email' => bool], 'reference_type'].
     *
     * @throws \InvalidArgumentException déclaration mal formée
     */
    public static function fromDeclaration(string $type, mixed $declaration): self
    {
        if (is_string($declaration)) {
            return new self($type, $declaration !== '' ? $declaration : '•');
        }
        if (!is_array($declaration)) {
            throw new \InvalidArgumentException("NotificationType : déclaration invalide pour « {$type} ».");
        }
        $channels = $declaration['channels'] ?? [];
        if (!is_array($channels)) {
            throw new \InvalidArgumentException("NotificationType : « channels » doit être un tableau pour « {$type} ».");
        }
        foreach (['icon' => 'string', 'category' => 'string'] as $key => $_) {
            if (isset($declaration[$key]) && !is_string($declaration[$key])) {
                throw new \InvalidArgumentException("NotificationType : « {$key} » doit être une chaîne pour « {$type} ».");
            }
        }
        foreach (['push', 'email'] as $channel) {
            if (isset($channels[$channel]) && !is_bool($channels[$channel])) {
                throw new \InvalidArgumentException("NotificationType : canal « {$channel} » doit être un booléen pour « {$type} ».");
            }
        }
        $reference = $declaration['reference_type'] ?? null;
        if ($reference !== null && !is_string($reference)) {
            throw new \InvalidArgumentException("NotificationType : « reference_type » doit être une chaîne pour « {$type} ».");
        }
        $icon = (string) ($declaration['icon'] ?? '•');

        return new self(
            $type,
            $icon !== '' ? $icon : '•',
            (string) ($declaration['category'] ?? 'system'),
            $channels['push'] ?? true,
            $channels['email'] ?? false,
            $reference,
        );
    }

    /** Valeur par défaut d'un canal de préférence (« push » ou « email ») ; faux pour un canal inconnu. */
    public function defaultFor(string $channel): bool
    {
        return match ($channel) {
            'push'  => $this->push,
            'email' => $this->email,
            default => false,
        };
    }
}
