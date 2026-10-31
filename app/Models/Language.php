<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Langue proposée par l'instance (table languages). Le fichier de traductions correspondant est lang/{code}.json.
 */
final class Language extends Model
{
    /** Langues livrées avec l'application (LanguageSeeder) ; sert aussi à l'installateur, avant que la base existe. */
    public const BUILT_IN = [
        'fr' => 'Français',
        'en' => 'English',
        'ja' => '日本語',
    ];

    protected $table = 'languages';
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /** @return list<array{code: string, name: string, is_active: bool, sort_order: int}> */
    public static function findAll(): array
    {
        return self::query()->orderBy('sort_order')->orderBy('code')->get()->map(fn(self $l): array => $l->toArray())->all();
    }

    public static function findAllActive(): array
    {
        return array_values(array_filter(self::findAll(), fn(array $l): bool => $l['is_active']));
    }

    public static function findByCode(string $code): ?array
    {
        return self::query()->find($code)?->toArray();
    }

    /** Ajoute ou met à jour une langue (et crée son fichier de traductions vide s'il manque). */
    public static function put(array $data): array
    {
        $code = (string) ($data['code'] ?? '');
        if (self::query()->find($code) === null) {
            $data += ['is_active' => true, 'sort_order' => (int) self::query()->count()];
        }
        self::query()->updateOrCreate(['code' => $code], $data);

        $file = lang_path($code . '.json');
        if ($code !== '' && !file_exists($file)) {
            file_put_contents($file, "{}\n");
        }

        return self::findByCode($code) ?? $data;
    }

    public static function toggleActive(string $code, bool $active): bool
    {
        return self::query()->where('code', $code)->update(['is_active' => $active]) > 0;
    }

    /** Retire la langue et son fichier de traductions. Retourne le nombre de langues supprimées. */
    public static function remove(string $code): int
    {
        $count = self::query()->where('code', $code)->delete();
        if ($count > 0 && is_file(lang_path($code . '.json'))) {
            unlink(lang_path($code . '.json'));
        }

        return $count;
    }

    /**
     * Langues que propose l'installateur avant que la base existe : celles livrées dont le fichier de traductions est
     * présent.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function installable(): array
    {
        $found = [];
        foreach (self::BUILT_IN as $code => $name) {
            if (is_file(lang_path($code . '.json'))) {
                $found[] = ['code' => $code, 'name' => $name];
            }
        }

        return $found;
    }
}
