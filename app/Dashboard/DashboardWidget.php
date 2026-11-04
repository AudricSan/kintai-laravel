<?php

namespace App\Dashboard;

use App\Models\User;
use Closure;

/**
 * Un widget des tableaux de bord. Objet valeur du contrat Core / bundles.
 */
final class DashboardWidget
{
    public const MANAGER = 'manager';

    public const EMPLOYEE = 'employee';

    /**
     * @param  string  $key  Identifiant stable, enregistré dans les dispositions.
     * @param  string  $label  Clé de traduction, traduite à l'affichage.
     * @param  string  $audience  `manager` ou `employee` : le tableau de bord où il est proposé.
     * @param  string  $component  Nom du composant Livewire qui le rend ; il lit lui-même l'utilisateur connecté.
     * @param  string|null  $permission  Capacité du Gate requise ; null = aucune.
     * @param  string|null  $storeFeature  Fonctionnalité de magasin requise : le widget n'apparaît que si au moins un
     *                                     magasin de l'utilisateur l'a activée ; null = aucune.
     * @param  int|null  $refresh  Intervalle de rafraîchissement en secondes ; null = pas de rafraîchissement.
     * @param  bool  $default  Inclus dans la disposition par défaut.
     * @param  bool  $resizable  L'utilisateur peut changer sa taille.
     * @param  (Closure(User): bool)|null  $when  Condition supplémentaire d'affichage (ex. au moins une source de données) ; null = toujours.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $audience,
        public readonly string $component,
        public readonly ?string $permission = null,
        public readonly ?string $storeFeature = null,
        public readonly WidgetSize $size = WidgetSize::Medium,
        public readonly ?int $refresh = null,
        public readonly int $order = 100,
        public readonly bool $default = true,
        public readonly bool $resizable = true,
        public readonly ?Closure $when = null,
    ) {}
}
