<?php

namespace App\Http\Resources\V1;

use App\Models\Store;
use Illuminate\Http\Request;
use Kintai\Contracts\Stores\StoreFeatureRegistry;

/**
 * Magasin avec ses réglages et ses fonctionnalités (clé du registre => activée).
 *
 * @mixin Store
 */
class StoreDetailResource extends StoreResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $features = [];
        foreach (app(StoreFeatureRegistry::class)->keys() as $key) {
            $features[$key] = $this->hasFeature($key);
        }

        return parent::toArray($request) + [
            'currency_symbol_style' => $this->currency_symbol_style,
            'low_staff_hour_start' => $this->low_staff_hour_start,
            'low_staff_hour_end' => $this->low_staff_hour_end,
            'min_staff_per_day' => $this->min_staff_per_day,
            'min_shift_minutes' => $this->min_shift_minutes,
            'max_shift_minutes' => $this->max_shift_minutes,
            'features' => $features,
        ];
    }
}
