<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Lien d'action fourni par un bundle dans un emplacement d'une page du Core (point d'extension `Bundle::actions()`,
 * contrat 1.6) : par exemple « Rapport de salaire » dans le menu d'une ligne de la liste du personnel.
 *
 * Le Core l'affiche dans le style de l'emplacement, si l'utilisateur a la permission `permission`, si la
 * fonctionnalité `storeFeature` est active et si `url` retourne une adresse pour le contexte donné.
 */
final class Action
{
    /** Menu « ⋮ » d'une ligne de la liste du personnel. Contexte : user_id, store_id (magasin principal). */
    public const EMPLOYEE_ROW = 'staff.employee.row';
    /** Carte « Rapports » de la fiche employé, une fois par magasin. Contexte : user_id, store_id, store_name. */
    public const EMPLOYEE_STORE = 'staff.employee.store';
    /** En-tête du rapport employés d'un magasin. Contexte : store_id. */
    public const STORE_REPORT = 'staff.store_report';
    /** Ligne du rapport employés d'un magasin. Contexte : store_id, user_id, period (jours). */
    public const STORE_REPORT_ROW = 'staff.store_report.row';
    /** Barre d'outils du planning, côté employé (liste, calendrier, journée). Contexte : vide. */
    public const SHIFTS_EMPLOYEE = 'scheduling.shifts.employee';

    public const SLOTS = [self::EMPLOYEE_ROW, self::EMPLOYEE_STORE, self::STORE_REPORT, self::STORE_REPORT_ROW, self::SHIFTS_EMPLOYEE];

    /** Styles : DEFAULT = celui de l'emplacement, PRIMARY = mis en avant, DANGER = action destructrice ou sensible. */
    public const DEFAULT = 'default';
    public const PRIMARY = 'primary';
    public const DANGER = 'danger';

    /**
     * @param string        $key          Identifiant unique dans l'emplacement
     * @param string        $slot         Une des constantes d'emplacement
     * @param string        $label        Clé de traduction du libellé
     * @param \Closure      $url          fn(array $contexte): ?string — adresse du lien, ou null pour ne pas l'afficher
     * @param string        $icon         Icône placée devant le libellé (un emoji), facultative
     * @param string        $style        DEFAULT, PRIMARY ou DANGER
     * @param string|null   $permission   Permission requise
     * @param string|null   $storeFeature Fonctionnalité par magasin requise
     * @param int           $order        Ordre dans l'emplacement
     */
    public function __construct(
        public readonly string $key,
        public readonly string $slot,
        public readonly string $label,
        public readonly \Closure $url,
        public readonly string $icon = '',
        public readonly string $style = self::DEFAULT,
        public readonly ?string $permission = null,
        public readonly ?string $storeFeature = null,
        public readonly int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("Action : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        if (!in_array($slot, self::SLOTS, true)) {
            throw new \InvalidArgumentException("Action « {$key} » : emplacement inconnu « {$slot} ».");
        }
        if (!in_array($style, [self::DEFAULT, self::PRIMARY, self::DANGER], true)) {
            throw new \InvalidArgumentException("Action « {$key} » : style inconnu « {$style} ».");
        }
    }
}
