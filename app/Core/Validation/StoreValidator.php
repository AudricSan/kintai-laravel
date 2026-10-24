<?php

declare(strict_types=1);

namespace kintai\Core\Validation;

use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Services\StoreTimezone;

final class StoreValidator implements ValidatorInterface
{
    /** Devises proposées par le formulaire magasin (liste fermée, partagée avec la vue et StoreService). */
    public const CURRENCIES = ['EUR', 'USD', 'JPY', 'GBP', 'CHF', 'KRW'];

    public const CURRENCY_SYMBOL_STYLES = ['kanji', 'international'];
    public function __construct(
        private readonly LanguageRepositoryInterface $languages,
    ) {}

    public function validate(array $data): ValidationResult
    {
        $errors = [];
        $fields = [
            'code' => $data['code'] ?? '',
            'name' => $data['name'] ?? '',
        ];

        $code = trim($fields['code']);
        $name = trim($fields['name']);

        if ($code === '') {
            $errors[] = __('val_store_code_required');
        } elseif (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            $errors[] = __('val_store_code_format');
        }

        if ($name === '') {
            $errors[] = __('val_store_name_required');
        } elseif (mb_strlen($name) > 200) {
            $errors[] = __('val_name_max_length_200');
        }

        $validTypes = ['retail', 'restaurant', 'office', 'warehouse', 'other'];
        $type = $data['type'] ?? 'retail';
        if (!in_array($type, $validTypes, true)) {
            $errors[] = __('val_invalid_store_type');
        }

        $locale = $data['locale'] ?? 'en';
        $activeCodes = array_column($this->languages->findAllActive(), 'code');
        if (!in_array($locale, $activeCodes, true)) {
            $errors[] = __('val_invalid_locale');
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('val_invalid_email');
        }

        if (!empty($data['phone']) && !preg_match('/^[+\-\s\d()]{6,20}$/', $data['phone'])) {
            $errors[] = __('val_invalid_phone');
        }

        array_push($errors, ...self::currencyErrors($data));
        array_push($errors, ...self::timezoneErrors($data));

        return new ValidationResult($errors === [], $errors);
    }

    /**
     * Le fuseau horaire doit être reconnaissable (Asia/Tokyo, ou une écriture comme « UTC+9 » que StoreTimezone convertit).
     * Une valeur quelconque était acceptée : Google Agenda l'ignorait et plaçait les événements en UTC (+9 h à Tokyo).
     * Un champ absent ou vide n'est pas une erreur : le service retombe sur le fuseau de l'instance.
     *
     * @return list<string>
     */
    public static function timezoneErrors(array $data): array
    {
        $timezone = trim((string) ($data['timezone'] ?? ''));

        return $timezone !== '' && StoreTimezone::normalize($timezone) === null ? [__('val_invalid_timezone')] : [];
    }

    /**
     * Contrôle de la devise et du style de symbole seuls, applicable aussi à la modification d'un magasin. Les autres
     * champs n'y sont pas revalidés : des magasins existants ont un type libre ou une langue hors liste, et les
     * bloquer empêcherait toute modification. La devise, elle, vient d'une liste fermée et s'affiche dans les vues.
     *
     * @return list<string>
     */
    public static function currencyErrors(array $data): array
    {
        $errors   = [];
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($currency !== '' && !in_array($currency, self::CURRENCIES, true)) {
            $errors[] = __('val_invalid_currency');
        }

        $symbolStyle = $data['currency_symbol_style'] ?? 'kanji';
        if (!in_array($symbolStyle, self::CURRENCY_SYMBOL_STYLES, true)) {
            $errors[] = __('val_invalid_currency_symbol_style');
        }

        return $errors;
    }
}
