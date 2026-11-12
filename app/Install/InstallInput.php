<?php

namespace App\Install;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Validation des saisies de l'installation (commande et assistant web) : le premier Owner, la base de données,
 * l'URL publique. Les champs de base de données ne sont exigés que si l'installation les écrit dans `.env`.
 */
final class InstallInput
{
    public const DRIVERS = ['sqlite', 'mysql'];

    /** Aucun saut de ligne ni octet nul : une valeur ne doit pas pouvoir ajouter de variables dans `.env`. */
    private const SINGLE_LINE = '/^[^\r\n\0]+$/';

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, password: string, db_driver: string, db_host: string|null, db_port: int|null, db_database: string|null, db_username: string|null, db_password: string|null, url: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(array $input, bool $withDatabase = true): array
    {
        $input['db_driver'] = $withDatabase ? ($input['db_driver'] ?? 'sqlite') : 'sqlite';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
            'url' => ['nullable', 'string', 'url:http,https', 'max:255', 'regex:'.self::SINGLE_LINE],
        ];

        if (array_key_exists('password_confirmation', $input)) {
            $rules['password'][] = 'confirmed';
        }

        if ($withDatabase) {
            $rules += [
                'db_driver' => ['required', 'in:'.implode(',', self::DRIVERS)],
                'db_host' => ['required_if:db_driver,mysql', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\[\]-]+$/'],
                'db_port' => ['nullable', 'integer', 'between:1,65535'],
                'db_database' => ['required_if:db_driver,mysql', 'nullable', 'string', 'max:64', 'regex:'.self::SINGLE_LINE],
                'db_username' => ['required_if:db_driver,mysql', 'nullable', 'string', 'max:255', 'regex:'.self::SINGLE_LINE],
                'db_password' => ['nullable', 'string', 'max:255', 'regex:'.self::SINGLE_LINE],
            ];
        }

        $data = Validator::make($input, $rules)->validate();

        $driver = $withDatabase ? $data['db_driver'] : 'sqlite';
        $mysql = $driver === 'mysql';

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'db_driver' => $driver,
            'db_host' => $mysql ? $data['db_host'] : null,
            'db_port' => $mysql && isset($data['db_port']) ? (int) $data['db_port'] : null,
            'db_database' => $mysql ? $data['db_database'] : null,
            'db_username' => $mysql ? $data['db_username'] : null,
            'db_password' => $mysql ? ($data['db_password'] ?? null) : null,
            'url' => $data['url'] ?? null,
        ];
    }
}
