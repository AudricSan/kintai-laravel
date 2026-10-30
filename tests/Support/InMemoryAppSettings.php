<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AppSetting;

/**
 * Réglages de l'instance pour les tests : la table app_settings d'une base de test neuve, préremplie avec les valeurs
 * données. Reste le « magasin » que les tests lisent et modifient ; `into()` livre le service testé une fois la base prête
 * (les services lisent les réglages à leur construction).
 *
 * @property-read array<string, string> $values réglages actuellement enregistrés
 */
final class InMemoryAppSettings
{
    /** @param array<string, string> $values */
    public function __construct(array $values = [])
    {
        kintai_test_settings($values);
    }

    /** Renvoie ce service, construit après la préparation des réglages. */
    public function into(object $service): object
    {
        return $service;
    }

    public function __get(string $name): array
    {
        if ($name === 'values') {
            return AppSetting::map();
        }
        throw new \LogicException("propriété inconnue : {$name}");
    }

    public function all(): array
    {
        return AppSetting::map();
    }

    public function get(string $key): ?string
    {
        return AppSetting::value($key);
    }

    public function set(string $key, string $value): void
    {
        AppSetting::put($key, $value);
    }

    public function setMany(array $settings): void
    {
        AppSetting::putMany($settings);
    }
}
