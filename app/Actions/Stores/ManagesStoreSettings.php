<?php

namespace App\Actions\Stores;

use App\Models\Store;
use App\Support\Locales;
use App\Support\StoreTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Kintai\Contracts\Stores\StoreFeatureRegistry;

/**
 * Normalisation, validation et enregistrement des réglages d'un magasin, communs à la création et à l'édition.
 */
trait ManagesStoreSettings
{
    /** @var list<string> */
    public const CURRENCIES = ['EUR', 'USD', 'JPY', 'GBP', 'CHF', 'KRW'];

    /** @var list<string> */
    public const CURRENCY_SYMBOL_STYLES = ['kanji', 'international'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeStoreInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }

        // Champs facultatifs : une chaîne vide devient null.
        foreach (['locale', 'email', 'phone', 'address_street', 'address_city', 'address_postal', 'address_country', 'low_staff_hour_start', 'low_staff_hour_end'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }

        if (is_string($input['code'] ?? null)) {
            $input['code'] = Str::upper($input['code']);
        }

        $currency = (string) ($input['currency'] ?? '');
        $input['currency'] = $currency === '' ? 'EUR' : Str::upper($currency);

        if (($input['currency_symbol_style'] ?? '') === '') {
            $input['currency_symbol_style'] = 'kanji';
        }

        // Un fuseau vide prend celui de l'application ; un fuseau inconnu est gardé tel quel pour être refusé.
        $raw = is_scalar($input['timezone'] ?? null) ? (string) $input['timezone'] : '';
        $input['timezone'] = StoreTimezone::resolve($raw) ?? $raw;

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeRules(?int $ignoreStoreId = null): array
    {
        $unique = Rule::unique('stores', 'code')->whereNull('deleted_at');
        if ($ignoreStoreId !== null) {
            $unique->ignore($ignoreStoreId);
        }

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,20}$/', $unique],
            'name' => ['required', 'string', 'max:200'],
            'timezone' => ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                if (StoreTimezone::normalize(is_string($value) ? $value : null) === null) {
                    $fail(__('This time zone is not valid.'));
                }
            }],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'currency_symbol_style' => ['required', Rule::in(self::CURRENCY_SYMBOL_STYLES)],
            'locale' => ['nullable', Rule::in(array_keys(Locales::SUPPORTED))],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^[+\-\s\d()]{6,20}$/'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:255'],
            'address_postal' => ['nullable', 'string', 'max:255'],
            'address_country' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'week_start_day' => ['required', 'integer', 'between:1,7'],
            'low_staff_hour_start' => ['nullable', 'integer', 'between:0,23'],
            'low_staff_hour_end' => ['nullable', 'integer', 'between:0,23'],
            'min_staff_per_day' => ['required', 'integer', 'min:0', 'max:65535'],
            'min_shift_minutes' => ['required', 'integer', 'between:0,1440'],
            'max_shift_minutes' => ['required', 'integer', 'between:0,1440', 'gte:min_shift_minutes'],
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validateStoreInput(array $input, ?int $ignoreStoreId = null): array
    {
        return Validator::make($this->normalizeStoreInput($input), $this->storeRules($ignoreStoreId))->validate();
    }

    /**
     * Données validées sans les fonctionnalités, qui ne sont pas des colonnes du magasin.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function storeAttributes(array $data): array
    {
        unset($data['features']);

        return $data;
    }

    /**
     * N'écrit que les clés du registre présentes dans la saisie : une clé inconnue (bundle retiré) garde sa ligne.
     *
     * @param  array<string, mixed>  $features  clé => activée
     */
    protected function syncStoreFeatures(Store $store, array $features): void
    {
        foreach (app(StoreFeatureRegistry::class)->keys() as $key) {
            if (! array_key_exists($key, $features)) {
                continue;
            }

            DB::table('store_features')->updateOrInsert(
                ['store_id' => $store->id, 'feature' => $key],
                ['enabled' => (bool) $features[$key]],
            );
        }
    }
}
