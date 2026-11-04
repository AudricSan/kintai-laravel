<?php

namespace App\Activity;

use App\Models\ActivityEntry;
use Illuminate\Support\Str;

/**
 * Présentation lisible d'une entrée : cible et propriétés (écran et export).
 */
final class ActivityDetails
{
    /** Libellés des propriétés courantes (clés de traduction) ; les autres s'affichent en clair, sans traduction. */
    public const PROPERTY_LABELS = [
        'name' => 'Name',
        'code' => 'Code',
        'source' => 'Source',
        'login' => 'Login entered',
        'employee' => 'Employee',
        'previous_employee' => 'Previous employee',
        'open_shift' => 'Open shift',
        'store' => 'Store',
        'previous_store' => 'Previous store',
        'shift_type' => 'Shift type',
        'date' => 'Date',
        'start' => 'Start',
        'end' => 'End',
        'previous_date' => 'Previous date',
        'previous_start' => 'Previous start',
        'previous_end' => 'Previous end',
        'replaced_shift_id' => 'Replaced shift',
        'change' => 'Change',
        'count' => 'Rows',
        'kept_days' => 'Days kept',
        'deleted' => 'Deleted entries',
        'filters' => 'Filters',
        'dataset' => 'Data set',
        'format' => 'Format',
        'stores' => 'Stores',
        'from' => 'From',
        'to' => 'To',
    ];

    private const CHANGES = ['added' => 'Added', 'removed' => 'Removed'];

    /** Libellé traduisible du type de cible, par nom de classe du modèle. */
    private const SUBJECTS = [
        'User' => 'Employee',
        'Store' => 'Store',
        'Shift' => 'Shift',
        'ShiftType' => 'Shift type',
        'Role' => 'Role',
    ];

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function lines(ActivityEntry $entry): array
    {
        $lines = [];

        foreach ($entry->properties ?? [] as $key => $value) {
            $key = (string) $key;
            $label = isset(self::PROPERTY_LABELS[$key]) ? __(self::PROPERTY_LABELS[$key]) : Str::headline($key);
            $lines[] = ['label' => $label, 'value' => self::value($key, $value)];
        }

        return $lines;
    }

    /**
     * « Libellé : valeur » séparés par « ; » (export).
     */
    public static function text(ActivityEntry $entry): string
    {
        return implode(' ; ', array_map(fn (array $line): string => $line['label'].' : '.$line['value'], self::lines($entry)));
    }

    /**
     * Cible : « Store #3 (Alpha) » ; vide sans cible.
     */
    public static function target(ActivityEntry $entry): string
    {
        if ($entry->subject_type === null) {
            return '';
        }

        $name = ($entry->properties ?? [])['name'] ?? null;
        $type = class_basename($entry->subject_type);
        $label = (isset(self::SUBJECTS[$type]) ? __(self::SUBJECTS[$type]) : $type).($entry->subject_id === null ? '' : ' #'.$entry->subject_id);

        return is_string($name) && $name !== '' ? $label.' ('.$name.')' : $label;
    }

    private static function value(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if ($key === 'change' && is_string($value) && isset(self::CHANGES[$value])) {
            return __(self::CHANGES[$value]);
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $name => $item) {
                $text = is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE);
                $items[] = array_is_list($value) ? $text : $name.' = '.$text;
            }

            return implode(', ', $items);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
