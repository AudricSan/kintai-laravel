<?php

declare(strict_types=1);

namespace App\Validation;

use App\Models\Language;
use App\Services\StoreTimezone;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Règles du formulaire magasin, appliquées par le validateur de Laravel. make() prépare la validation d'un nouveau
 * magasin ; forUpdate() ne contrôle que la devise, son style de symbole et le fuseau horaire.
 */
final class StoreValidator
{
    /** Devises proposées par le formulaire magasin (liste fermée, partagée avec la vue et StoreService). */
    public const CURRENCIES = ['EUR', 'USD', 'JPY', 'GBP', 'CHF', 'KRW'];

    public const CURRENCY_SYMBOL_STYLES = ['kanji', 'international'];

    private const TYPES = ['retail', 'restaurant', 'office', 'warehouse', 'other'];

    /** Validation d'un nouveau magasin : $validator->validate() lève l'exception de Laravel au premier refus. */
    public static function make(array $data): \Illuminate\Validation\Validator
    {
        $activeCodes = array_column(Language::findAllActive(), 'code');

        $input = [
            'code'                  => trim((string) ($data['code'] ?? '')),
            'name'                  => trim((string) ($data['name'] ?? '')),
            'type'                  => $data['type'] ?? 'retail',
            'locale'                => $data['locale'] ?? 'en',
            'email'                 => $data['email'] ?? null,
            'phone'                 => $data['phone'] ?? null,
        ] + self::currencyInput($data) + ['timezone' => $data['timezone'] ?? null];

        return Validator::make($input, [
            'code'   => ['required', 'regex:/^[A-Z0-9_-]{2,20}$/'],
            'name'   => ['required', 'max:200'],
            'type'   => [Rule::in(self::TYPES)],
            'locale' => [Rule::in($activeCodes)],
            'email'  => ['nullable', 'email'],
            'phone'  => ['nullable', 'regex:/^[+\-\s\d()]{6,20}$/'],
        ] + self::currencyRules(), [
            'code.required'                => __('val_store_code_required'),
            'code.regex'                   => __('val_store_code_format'),
            'name.required'                => __('val_store_name_required'),
            'name.max'                     => __('val_name_max_length_200'),
            'type.in'                      => __('val_invalid_store_type'),
            'locale.in'                    => __('val_invalid_locale'),
            'email.email'                  => __('val_invalid_email'),
            'phone.regex'                  => __('val_invalid_phone'),
        ] + self::currencyMessages());
    }

    /**
     * Modification d'un magasin : seuls la devise, le style de symbole et le fuseau horaire sont revalidés. Des
     * magasins existants ont un type libre ou une langue hors liste, et les bloquer empêcherait toute modification.
     * La devise, elle, vient d'une liste fermée et s'affiche dans les vues ; le fuseau horaire doit être reconnaissable
     * (Asia/Tokyo, ou une écriture comme « UTC+9 » que StoreTimezone convertit) : une valeur quelconque était acceptée,
     * Google Agenda l'ignorait et plaçait les événements en UTC (+9 h à Tokyo). Un champ absent ou vide n'est pas une
     * erreur : le service retombe sur le fuseau de l'instance.
     */
    public static function forUpdate(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make(
            self::currencyInput($data) + ['timezone' => $data['timezone'] ?? null],
            self::currencyRules(),
            self::currencyMessages(),
        );
    }

    /** @return array<string, mixed> */
    private static function currencyInput(array $data): array
    {
        return [
            'currency'              => strtoupper(trim((string) ($data['currency'] ?? ''))),
            'currency_symbol_style' => $data['currency_symbol_style'] ?? 'kanji',
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function currencyRules(): array
    {
        return [
            'currency'              => ['nullable', Rule::in(self::CURRENCIES)],
            'currency_symbol_style' => [Rule::in(self::CURRENCY_SYMBOL_STYLES)],
            'timezone'              => ['nullable', static function (string $attribute, mixed $value, Closure $fail): void {
                if (trim((string) $value) !== '' && StoreTimezone::normalize(trim((string) $value)) === null) {
                    $fail(__('val_invalid_timezone'));
                }
            }],
        ];
    }

    /** @return array<string, string> */
    private static function currencyMessages(): array
    {
        return [
            'currency.in'              => __('val_invalid_currency'),
            'currency_symbol_style.in' => __('val_invalid_currency_symbol_style'),
        ];
    }
}
