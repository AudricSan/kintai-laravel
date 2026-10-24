<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Carte de statistique du haut du tableau de bord employé fournie par un bundle (point d'extension
 * `Bundle::dashboardStats()`, contrat 1.19) : par exemple « Échanges » (shift-swap) ou « Congés en attente » (timeoff).
 *
 * Le Core l'affiche après ses propres cartes (shifts du jour, à venir), triée par ordre, avec la valeur que renvoie
 * `value` pour l'employé connecté. `value` reçoit le même contexte que les widgets du tableau de bord (user, store_ids,
 * stores_map, today). Avec `storeFeature`, la carte n'apparaît que si cette fonctionnalité est active. Une exception
 * retire la carte et est journalisée, sans casser la page.
 */
final class DashboardStat
{
    /** Tons de l'icône, ceux des cartes du Core. */
    public const TONES = ['primary', 'success', 'warning', 'danger'];

    /**
     * @param string                              $key          Identifiant unique de la carte
     * @param string                              $label        Clé de traduction du libellé
     * @param \Closure(array<string, mixed>): (int|string) $value Valeur affichée pour l'employé connecté
     * @param string                              $icon         Icône (emoji)
     * @param string                              $tone         Ton de l'icône (TONES)
     * @param string|null                         $storeFeature Fonctionnalité par magasin requise
     * @param int                                 $order        Ordre parmi les cartes des bundles
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly \Closure $value,
        public readonly string $icon = '',
        public readonly string $tone = 'primary',
        public readonly ?string $storeFeature = null,
        public readonly int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("DashboardStat : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        if (!in_array($tone, self::TONES, true)) {
            throw new \InvalidArgumentException("DashboardStat « {$key} » : ton invalide « {$tone} ».");
        }
    }
}
