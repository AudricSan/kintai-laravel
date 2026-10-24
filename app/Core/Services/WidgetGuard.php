<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Isole le chargement des données d'un widget fourni par un bundle : si le bundle est actif mais défaillant
 * (table absente après une mise à jour sans migration, base incomplète…), le widget disparaît et l'erreur est
 * journalisée, au lieu de faire tomber tout le tableau de bord (erreur 500 pour tout le monde).
 */
final class WidgetGuard
{
    /**
     * @template T
     * @param callable(): T $load
     * @param T $fallback Valeur rendue quand le chargement échoue (liste vide en général)
     * @return T
     */
    public static function run(string $widget, callable $load, mixed $fallback = []): mixed
    {
        try {
            return $load();
        } catch (\Throwable $e) {
            try {
                Log::error('Widget "' . $widget . '" indisponible : ' . $e->getMessage(), ['widget' => $widget, 'exception' => $e::class]);
            } catch (\Throwable) {
                // Le journal lui-même peut dépendre de la base : ne jamais aggraver la panne.
            }
            return $fallback;
        }
    }
}
