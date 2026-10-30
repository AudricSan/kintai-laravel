<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AppSetting extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    public $timestamps = false;

    public static function map(): array
    {
        $rows = static::all();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['key']] = $row['value'];
        }
        return $map;
    }

    /** Valeur d'un réglage de l'instance, ou `$default` s'il n'a jamais été enregistré. */
    public static function value(string $key, ?string $default = null): ?string
    {
        $record = static::find($key);
        return $record ? $record->value : $default;
    }

    public static function put(string $key, string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public static function putMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            static::put((string) $key, (string) $value);
        }
    }
}
