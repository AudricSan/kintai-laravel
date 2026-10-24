<?php

declare(strict_types=1);

namespace kintai\Core\Validation;

/**
 * Règles de PayloadValidator pour chaque ressource écrite par l'API v1 (audit du 13/10/2026, M9).
 * Seuls les champs listés ici peuvent être écrits ; les colonnes techniques (dates de création et de
 * suppression, empreinte de mot de passe, champs calculés des shifts…) n'y figurent pas.
 */
final class ApiPayloadRules
{
    /** Code employé : même contrainte que le formulaire web (segment d'URL, voir EmployeeRouteBinder). */
    private const EMPLOYEE_CODE = '/\A(?!ID-)[A-Za-z0-9_-]{1,50}\z/i';
    private const STORE_CODE = '/\A[A-Za-z0-9_-]{2,20}\z/';
    private const LANGUAGE = '/\A[a-z]{2}(?:[-_][A-Za-z]{2,4})?\z/';

    /** @return array<string, array<string, mixed>> */
    public static function user(): array
    {
        return [
            'email'               => ['type' => 'email', 'required' => true, 'max' => 255],
            'first_name'          => ['type' => 'string', 'required' => true, 'max' => 100],
            'last_name'           => ['type' => 'string', 'required' => true, 'max' => 100],
            'display_name'        => ['type' => 'string', 'max' => 200],
            'furigana_last_name'  => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'furigana_first_name' => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'employee_code'       => ['type' => 'string', 'nullable' => true, 'max' => 50, 'pattern' => self::EMPLOYEE_CODE],
            'phone'               => ['type' => 'string', 'nullable' => true, 'max' => 30],
            'mobile_phone'        => ['type' => 'string', 'nullable' => true, 'max' => 30],
            'color'               => ['type' => 'color'],
            'is_active'           => ['type' => 'bool'],
            // null ou absent à la création : le compte suit la langue de l'instance.
            'language'            => ['type' => 'string', 'nullable' => true, 'max' => 10, 'pattern' => self::LANGUAGE],
            'gender'              => ['type' => 'string', 'nullable' => true, 'max' => 20],
            'tax_classification'  => ['type' => 'string', 'nullable' => true, 'max' => 50],
            'birth_date'          => ['type' => 'date', 'nullable' => true],
            'education'           => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'postal_code'         => ['type' => 'string', 'nullable' => true, 'max' => 20],
            'address'             => ['type' => 'text', 'nullable' => true, 'max' => 500],
            'guarantor_name'      => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'guarantor_phone'     => ['type' => 'string', 'nullable' => true, 'max' => 30],
            'bio'                 => ['type' => 'text', 'nullable' => true, 'max' => 2000],
            'skills'              => ['type' => 'text', 'nullable' => true, 'max' => 1000],
            'languages_spoken'    => ['type' => 'text', 'nullable' => true, 'max' => 1000],
            'hobbies'             => ['type' => 'text', 'nullable' => true, 'max' => 1000],
            'show_in_directory'   => ['type' => 'bool'],
            'share_email'         => ['type' => 'bool'],
            'share_phone'         => ['type' => 'bool'],
            'share_mobile_phone'  => ['type' => 'bool'],
        ];
    }

    /**
     * Modification d'un magasin (la création passe par StoreService, déjà validée par StoreValidator).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function storeUpdate(): array
    {
        return [
            'code'                  => ['type' => 'string', 'required' => true, 'pattern' => self::STORE_CODE],
            'name'                  => ['type' => 'string', 'required' => true, 'max' => 200],
            'type'                  => ['type' => 'enum', 'in' => ['retail', 'restaurant', 'office', 'warehouse', 'other']],
            'timezone'              => ['type' => 'enum', 'in' => \DateTimeZone::listIdentifiers()],
            'locale'                => ['type' => 'string', 'max' => 10, 'pattern' => self::LANGUAGE],
            'currency'              => ['type' => 'enum', 'in' => StoreValidator::CURRENCIES],
            'currency_symbol_style' => ['type' => 'enum', 'in' => StoreValidator::CURRENCY_SYMBOL_STYLES],
            'phone'                 => ['type' => 'string', 'nullable' => true, 'max' => 30],
            'email'                 => ['type' => 'email', 'nullable' => true, 'max' => 255],
            'address_street'        => ['type' => 'string', 'nullable' => true, 'max' => 255],
            'address_city'          => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'address_postal'        => ['type' => 'string', 'nullable' => true, 'max' => 20],
            'address_country'       => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'is_active'             => ['type' => 'bool'],
            'week_start_day'        => ['type' => 'int', 'min' => 1, 'max' => 7],
            'min_shift_minutes'     => ['type' => 'int', 'min' => 0, 'max' => 1440],
            'max_shift_minutes'     => ['type' => 'int', 'min' => 0, 'max' => 1440],
            'min_staff_per_day'     => ['type' => 'int', 'min' => 0, 'max' => 10000],
            'low_staff_hour_start'  => ['type' => 'int', 'min' => -1, 'max' => 23],
            'low_staff_hour_end'    => ['type' => 'int', 'min' => -1, 'max' => 23],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function shiftType(): array
    {
        return [
            'code'        => ['type' => 'string', 'required' => true, 'max' => 20],
            'name'        => ['type' => 'string', 'required' => true, 'max' => 100],
            'start_time'  => ['type' => 'time', 'required' => true],
            'end_time'    => ['type' => 'time', 'required' => true],
            'hourly_rate' => ['type' => 'number', 'min' => 0, 'max' => 1000000],
            'color'       => ['type' => 'color'],
            'sort_order'  => ['type' => 'int', 'min' => 0, 'max' => 100000],
            'is_active'   => ['type' => 'bool'],
        ];
    }

    /**
     * Les champs calculés (durée, passage de minuit, starts_at/ends_at) ne sont pas acceptés : le
     * contrôleur les dérive de shift_date, start_time, end_time.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function shift(): array
    {
        return [
            'store_id'             => ['type' => 'id', 'required' => true],
            'user_id'              => ['type' => 'id', 'nullable' => true],
            'shift_type_id'        => ['type' => 'id', 'nullable' => true],
            'shift_date'           => ['type' => 'date', 'required' => true],
            'start_time'           => ['type' => 'time', 'required' => true],
            'end_time'             => ['type' => 'time', 'required' => true],
            'pause_minutes'        => ['type' => 'int', 'min' => 0, 'max' => 1440],
            'notes'                => ['type' => 'text', 'nullable' => true, 'max' => 2000],
            'is_open'              => ['type' => 'bool'],
            'open_shift_note'      => ['type' => 'text', 'nullable' => true, 'max' => 1000],
            'hourly_rate_override' => ['type' => 'number', 'nullable' => true, 'min' => 0, 'max' => 1000000],
            'net_minutes_override' => ['type' => 'int', 'nullable' => true, 'min' => 0, 'max' => 1440],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function availability(): array
    {
        return [
            'user_id'      => ['type' => 'id', 'required' => true],
            'store_id'     => ['type' => 'id', 'required' => true],
            'day_of_week'  => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 6],
            'start_time'   => ['type' => 'time', 'required' => true],
            'end_time'     => ['type' => 'time', 'required' => true],
            'is_available' => ['type' => 'bool'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function userRate(): array
    {
        return [
            'shift_type_id' => ['type' => 'id', 'required' => true],
            'hourly_rate'   => ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 1000000],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function dashboardPrefs(): array
    {
        return [
            'dashboard_type' => ['type' => 'enum', 'in' => ['admin', 'employee']],
            'widgets'        => ['type' => 'list', 'nullable' => true, 'max' => 100],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function navPrefs(): array
    {
        return [
            'hidden' => ['type' => 'list', 'nullable' => true, 'max' => 200],
        ];
    }

    /**
     * Champs calculés d'un shift à partir de sa date et de ses heures (même calcul que le formulaire web).
     *
     * @return array{duration_minutes: int, cross_midnight: int, starts_at: string, ends_at: string}
     */
    public static function shiftTiming(string $shiftDate, string $startTime, string $endTime): array
    {
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));
        $start = $sh * 60 + $sm;
        $end   = $eh * 60 + $em;

        $crossMidnight = $end < $start ? 1 : 0;
        $duration      = $end - $start + ($crossMidnight ? 1440 : 0);
        $endsDate      = $crossMidnight ? date('Y-m-d', strtotime($shiftDate . ' +1 day')) : $shiftDate;

        return [
            'duration_minutes' => $duration,
            'cross_midnight'   => $crossMidnight,
            'starts_at'        => $shiftDate . ' ' . $startTime . ':00',
            'ends_at'          => $endsDate . ' ' . $endTime . ':00',
        ];
    }
}
