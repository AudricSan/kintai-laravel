<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\BundleDiscoveryService;
use kintai\Core\FeatureManager;
use kintai\Core\LicenseServiceProvider;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/**
 * Activation des bundles dans la limite du quota de la licence (plan gratuit : 4 bundles actifs).
 *
 * - Un bundle installé depuis le catalogue (ou l'assistant de configuration) est activé s'il reste de la place,
 *   sinon il reste installé mais inactif.
 * - Une instance au-dessus du quota (installation antérieure, licence expirée) continue de tourner : l'Owner peut
 *   toujours réduire, un bundle à la fois, mais pas en ajouter tant qu'il dépasse.
 */
final class BundleActivationService
{
    public const ACTIVATED  = 'activated';
    public const ALREADY    = 'already';
    public const QUOTA_FULL = 'quota_full';

    public function __construct(
        private readonly AppSettingsRepositoryInterface $appSettings,
        private readonly FeatureManager $features,
        private readonly BundleDiscoveryService $discovery,
        private readonly PlanLimitService $planLimits,
    ) {}

    /**
     * Slugs des bundles présents sur l'instance et activés. Le réglage enregistré fait foi quand il existe : il peut
     * avoir changé depuis le démarrage de la requête (plusieurs activations à la suite, assistant de configuration),
     * alors que FeatureManager reflète l'état lu au démarrage.
     *
     * @return list<string>
     */
    public function enabledSlugs(): array
    {
        $stored = json_decode((string) $this->appSettings->get(LicenseServiceProvider::SETTINGS_KEY), true);
        $isEnabled = is_array($stored)
            ? static fn(string $slug): bool => in_array($slug, $stored, true)
            : fn(string $slug): bool => $this->features->isEnabled($slug);

        return array_values(array_filter(array_keys($this->discovery->discover()), $isEnabled));
    }

    /** Nombre de bundles encore activables selon le quota, ou null si illimité. */
    public function room(): ?int
    {
        $max = $this->planLimits->maxActiveBundles();
        return $max === null ? null : max(0, $max - count($this->enabledSlugs()));
    }

    /** Active le bundle s'il reste de la place dans le quota : ACTIVATED, ALREADY ou QUOTA_FULL. */
    public function activateIfRoom(string $slug): string
    {
        $enabled = $this->enabledSlugs();
        if (in_array($slug, $enabled, true)) {
            return self::ALREADY;
        }
        $max = $this->planLimits->maxActiveBundles();
        if ($max !== null && count($enabled) >= $max) {
            return self::QUOTA_FULL;
        }
        $enabled[] = $slug;
        $this->appSettings->set(LicenseServiceProvider::SETTINGS_KEY, json_encode($enabled, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::ACTIVATED;
    }

    /**
     * Le passage de $old à $new est-il permis ? Toujours sous le quota ; au-dessus, seulement s'il n'ajoute aucun
     * bundle (une réduction, même partielle, reste possible).
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    public static function allowsChange(array $old, array $new, ?int $max): bool
    {
        return $max === null || count($new) <= $max || array_diff($new, $old) === [];
    }
}
