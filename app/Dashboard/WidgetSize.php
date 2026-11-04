<?php

namespace App\Dashboard;

/**
 * Largeur d'un widget dans la grille de 12 colonnes du tableau de bord.
 */
enum WidgetSize: string
{
    case Small = 'sm';
    case Medium = 'md';
    case Large = 'lg';
    case Full = 'full';

    /**
     * Classes Tailwind de la cellule de grille.
     */
    public function cssClass(): string
    {
        return match ($this) {
            self::Small => 'col-span-12 sm:col-span-6 lg:col-span-3',
            self::Medium => 'col-span-12 lg:col-span-6',
            self::Large => 'col-span-12 lg:col-span-9',
            self::Full => 'col-span-12',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Small',
            self::Medium => 'Medium',
            self::Large => 'Large',
            self::Full => 'Full width',
        };
    }
}
