<?php

namespace App\Http\Controllers\Api\V1;

use App\Bundles\BundleManager;
use App\Http\Controllers\Controller;
use App\Push\FcmClient;
use App\Push\VapidKeys;
use Illuminate\Http\JsonResponse;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Laravel\Fortify\Features;

/**
 * Point de découverte public : une application mobile s'y connecte avant de s'authentifier pour reconnaître l'instance
 * et savoir ce qu'elle propose. Rien de sensible.
 */
class PingController extends Controller
{
    public function __invoke(FcmClient $fcm, VapidKeys $webPush, StoreFeatureRegistry $storeFeatures, BundleManager $bundles): JsonResponse
    {
        $features = ['ical'];

        if (Features::enabled(Features::twoFactorAuthentication())) {
            $features[] = 'two_factor';
        }

        if ($fcm->isEnabled()) {
            $features[] = 'push_fcm';
        }

        if ($webPush->isConfigured()) {
            $features[] = 'push_web';
        }

        // Fonctionnalités de magasin déclarées au registre (planning, et celles des bundles installés).
        foreach ($storeFeatures->keys() as $key) {
            $features[] = $key;
        }

        // Bundles activés, préfixés (`bundle:timeoff`) pour ne pas se confondre avec les fonctionnalités de magasin.
        foreach ($bundles->enabledSlugs() as $slug) {
            $features[] = 'bundle:'.$slug;
        }

        return response()->json([
            'name' => (string) config('app.name'),
            'version' => (string) config('kintai.version'),
            'api_version' => (string) config('kintai.api.version'),
            'features' => array_values(array_unique($features)),
            'locale' => (string) config('app.locale'),
            'timezone' => (string) config('kintai.timezone'),
        ]);
    }
}
