<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\BundleDiscoveryService;
use App\FeatureManager;
use App\LicenseServiceProvider;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\BundleActivationService;
use App\Services\PlanLimitService;
use App\Http\Controllers\HasBaseUrl;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class BundleSettingsController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewFactory $view,
        private readonly FeatureManager $features,
        private readonly AuditLogger $auditLogger,
        private readonly BundleDiscoveryService $discovery,
        private readonly PlanLimitService $planLimits,
    ) {}

    /** GET /admin/bundles */
    public function show(Request $request): Response
    {

        $official = $this->officialBundleSlugs();
        $bundles = [];
        foreach ($this->discovery->discover() as $key => $meta) {
            $bundles[] = [
                'key'      => $key,
                'label'    => $meta['label'],
                'desc'     => $meta['description'],
                'version'  => $meta['version'],
                'enabled'  => $this->features->isEnabled($key),
                'official' => in_array($key, $official, true),
                'incompatible' => $meta['incompatible'] ?? null,
            ];
        }

        $maxActiveBundles = $this->planLimits->maxActiveBundles();
        $activeCount = count(array_filter($bundles, fn(array $b) => $b['enabled']));

        return response($this->view->make('system.bundles', [
            'title'            => __('bundle_settings'),
            'bundles'          => $bundles,
            'success'          => isset($_GET['success']),
            'maxActiveBundles' => $maxActiveBundles,
            'activeCount'      => $activeCount,
        ])->render());
    }

    /** POST /admin/bundles */
    public function save(Request $request): Response
    {

        $discoveredSlugs = array_keys($this->discovery->discover());

        $oldEnabled = array_values(array_filter(
            $discoveredSlugs,
            fn(string $key) => $this->features->isEnabled($key),
        ));

        $enabled = [];
        foreach ($discoveredSlugs as $key) {
            if ($request->post('bundle_' . $key)) {
                $enabled[] = $key;
            }
        }

        // Au-dessus du quota, seul l'ajout est refusé : l'Owner peut toujours réduire, un bundle à la fois.
        $maxActiveBundles = $this->planLimits->maxActiveBundles();
        if (!BundleActivationService::allowsChange($oldEnabled, $enabled, $maxActiveBundles)) {
            return redirect()->away($this->base() . '/admin/bundles?error=bundle_quota_exceeded');
        }

        AppSetting::put(
            LicenseServiceProvider::SETTINGS_KEY,
            json_encode($enabled, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $this->auditLogger->logUpdate($request, 'bundle_settings.updated', 'system', null, [
            'enabled_bundles' => $oldEnabled,
        ], [
            'enabled_bundles' => $enabled,
        ], []);

        return redirect()->away($this->base() . '/admin/bundles?success=1');
    }

    private function officialBundleSlugs(): array
    {
        $path = BASE_PATH . '/config/official-bundles.php';
        return file_exists($path) ? (require $path) : [];
    }
}
