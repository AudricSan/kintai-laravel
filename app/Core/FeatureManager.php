<?php

declare(strict_types=1);

namespace kintai\Core;

final class FeatureManager
{
    private array $enabledFeatures = [];

    public function __construct(array $enabledFeatures = [])
    {
        $this->enabledFeatures = $enabledFeatures;
    }

    /**
     * Check if a specific feature or bundle is enabled.
     */
    public function isEnabled(string $feature): bool
    {
        return in_array($feature, $this->enabledFeatures, true);
    }

    /**
     * Enable a feature dynamically (e.g. after verifying a license).
     */
    public function enable(string $feature): void
    {
        if (!$this->isEnabled($feature)) {
            $this->enabledFeatures[] = $feature;
        }
    }

    /**
     * Get all enabled features.
     */
    public function getEnabledFeatures(): array
    {
        return $this->enabledFeatures;
    }
}
