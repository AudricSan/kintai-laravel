<?php

namespace App\Settings;

use App\Models\AppSetting;
use App\Update\UpdateChannel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kintai\Contracts\Settings\SettingDefinition;
use Kintai\Contracts\Settings\Settings;
use Throwable;

/**
 * Réglages de l'instance. La base (`app_settings`) fait foi à l'exécution ; une clé absente retombe sur la configuration
 * (`config/kintai.php`, donc l'environnement), qui reste le défaut d'installation.
 *
 * Chaque réglage est déclaré par une `SettingDefinition` (le Core dans `CoreSettings`, les bundles dans leur propre
 * fournisseur) ; une clé non déclarée est refusée. Les valeurs sont typées à la lecture, bornées à l'écriture et les
 * secrets sont chiffrés en base. Lecture : cache de requête + `Cache` (invalidé à chaque écriture). Une base ou un cache
 * indisponibles (avant la migration par exemple) ne cassent jamais la lecture : on retombe sur la configuration.
 */
class AppSettings implements Settings
{
    private const CACHE_KEY = 'kintai.app_settings';

    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    /** @var array<string, mixed>|null Valeurs brutes (secrets encore chiffrés), lues une fois par requête. */
    private ?array $rows = null;

    public function register(SettingDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->key] = $definition;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function definition(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    /**
     * La base contient-elle une valeur pour cette clé (sinon : repli sur la configuration) ?
     */
    public function isStored(string $key): bool
    {
        $this->definition($key);

        return array_key_exists($key, $this->rows());
    }

    /**
     * Valeur typée : base, sinon configuration, sinon défaut.
     */
    public function get(string $key): mixed
    {
        $definition = $this->definition($key);
        $rows = $this->rows();

        if (array_key_exists($key, $rows)) {
            if (! $definition->encrypted) {
                return $this->cast($definition, $rows[$key]);
            }

            $decrypted = $this->decrypt($rows[$key]);

            if ($decrypted !== null) {
                return $this->cast($definition, $decrypted);
            }
        }

        return $this->cast($definition, $definition->fallback());
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Écrit plusieurs réglages d'un coup (une transaction). Les entiers sont ramenés dans leurs bornes.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        $prepared = [];

        foreach ($values as $key => $value) {
            $definition = $this->definition($key);
            $normalized = $this->normalize($definition, $value);

            $prepared[$key] = $definition->encrypted && $normalized !== ''
                ? Crypt::encryptString((string) $normalized)
                : $normalized;
        }

        DB::transaction(function () use ($prepared): void {
            foreach ($prepared as $key => $value) {
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        $this->flush();
    }

    /**
     * Supprime les valeurs en base : les clés retombent sur la configuration.
     */
    public function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->definition($key);
        }

        AppSetting::query()->whereIn('key', $keys)->delete();

        $this->flush();
    }

    /**
     * Oublie le cache (requête et `Cache`) : à appeler après toute écriture hors de ce service.
     */
    public function flush(): void
    {
        $this->rows = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Cache indisponible : la prochaine lecture ira en base.
        }
    }

    // -- Organisation -------------------------------------------------------------------------------------------

    public function subtitle(): string
    {
        return $this->string('organization.subtitle');
    }

    public function loginNotice(): string
    {
        return $this->string('organization.login_notice');
    }

    public function supportEmail(): string
    {
        return $this->string('organization.support_email');
    }

    /** URL publique normalisée, ou chaîne vide (alors `APP_URL` fait foi). */
    public function publicUrl(): string
    {
        return $this->string('organization.public_url');
    }

    // -- Apparence ----------------------------------------------------------------------------------------------

    /** Couleur claire d'un groupe du thème (valeur par défaut de la palette si absente ou invalide). */
    public function themeColor(string $group): string
    {
        $value = strtolower($this->string('theme.'.$group));

        return ThemeColorPalette::isValidHex($value) ? $value : ThemeColorPalette::DEFAULTS[$group];
    }

    /** Couleur sombre choisie à la main pour un groupe ('' = calculée). */
    public function themeColorDark(string $group): string
    {
        $value = strtolower($this->string('theme.'.$group.'_dark'));

        return ThemeColorPalette::isValidHex($value) ? $value : '';
    }

    /** `auto` (variantes sombres calculées) ou `manual` (couleurs sombres choisies). */
    public function themeDarkMode(): string
    {
        return $this->string('theme.dark_mode') === 'manual' ? 'manual' : 'auto';
    }

    /** Feuille de style du thème personnalisé ('' tant que rien n'est personnalisé). */
    public function themeCss(): string
    {
        $light = [];
        $dark = [];

        foreach (array_keys(ThemeColorPalette::DEFAULTS) as $group) {
            $light[$group] = $this->themeColor($group);
            $dark[$group] = $this->themeColorDark($group);
        }

        return ThemeColorPalette::toCss($light, $dark, $this->themeDarkMode());
    }

    // -- Mails --------------------------------------------------------------------------------------------------

    /** Adresse d'expédition choisie par l'Owner ('' = celle de `config/mail.php`). */
    public function mailFromAddress(): string
    {
        $address = $this->string('mail.from_address');

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '';
    }

    public function mailFromName(): string
    {
        return $this->string('mail.from_name');
    }

    // -- Instance -----------------------------------------------------------------------------------------------

    public function instanceLocale(): string
    {
        return $this->string('instance.locale');
    }

    public function instanceTimezone(): string
    {
        return $this->string('instance.timezone');
    }

    // -- Maintenance --------------------------------------------------------------------------------------------

    public function maintenanceEnabled(): bool
    {
        return $this->bool('maintenance.enabled');
    }

    public function maintenanceMessage(): string
    {
        return $this->string('maintenance.message');
    }

    // -- Limites et rétentions ----------------------------------------------------------------------------------

    /** Requêtes API par minute et par jeton ; 0 = sans limite. */
    public function apiRateLimit(): int
    {
        return max(0, $this->int('api.rate_limit'));
    }

    /** Jours de conservation du journal d'activité ; 0 = illimité. */
    public function activityRetentionDays(): int
    {
        return max(0, $this->int('activity.retention_days'));
    }

    /** Valeur brute ; `PurgeNotifications::retentionDays()` la ramène entre 7 et 3650. */
    public function notificationsRetentionDays(): int
    {
        return $this->int('notifications.retention_days');
    }

    // -- Sauvegardes --------------------------------------------------------------------------------------------

    /** Sauvegarde automatique quotidienne activée ? */
    public function backupAutoEnabled(): bool
    {
        return $this->bool('backup.auto_enabled');
    }

    /** Nombre de sauvegardes conservées par la rotation ; 0 = illimité. */
    public function backupMaxKeep(): int
    {
        return max(0, $this->int('backup.max_keep'));
    }

    /** Dernière sauvegarde automatique réussie (jamais : null). */
    public function backupLastSuccessAt(): ?CarbonImmutable
    {
        $value = $this->string('backup.last_success_at');

        try {
            return $value === '' ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Message du dernier échec de la sauvegarde automatique ('' après un succès). */
    public function backupLastFailure(): string
    {
        return $this->string('backup.last_failure');
    }

    // -- Mises à jour -------------------------------------------------------------------------------------------

    /** Canal de mise à jour suivi ; un réglage invalide retombe sur les versions stables. */
    public function updateChannel(): UpdateChannel
    {
        return UpdateChannel::tryFrom($this->string('update.channel')) ?? UpdateChannel::Release;
    }

    // -- Alertes système et documentation ---------------------------------------------------------------------

    /** Dernière passe des alertes système (horodatage Unix ; 0 = jamais). */
    public function systemAlertsLastRun(): int
    {
        return max(0, $this->int('system.alerts_last_run'));
    }

    /** Dernière synchronisation réussie du wiki (jamais : null). */
    public function docsLastSyncAt(): ?CarbonImmutable
    {
        $value = $this->string('docs.last_sync_at');

        try {
            return $value === '' ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    // -- Web Push -----------------------------------------------------------------------------------------------

    public function webPushPublicKey(): string
    {
        return $this->string('webpush.public_key');
    }

    public function webPushPrivateKey(): string
    {
        return $this->string('webpush.private_key');
    }

    // -- Interne ------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rows(): array
    {
        return $this->rows ??= $this->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->fromDatabase());
        } catch (Throwable) {
            // Cache indisponible (table absente avant la migration, par exemple) : on lit la base directement.
        }

        try {
            return $this->fromDatabase();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fromDatabase(): array
    {
        return AppSetting::query()->get()->mapWithKeys(fn (AppSetting $row): array => [$row->key => $row->value])->all();
    }

    private function decrypt(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Clé d'application changée : le secret est perdu, on retombe sur la configuration.
            return null;
        }
    }

    private function cast(SettingDefinition $definition, mixed $value): string|int|bool
    {
        return match ($definition->type) {
            SettingDefinition::TYPE_INT => is_numeric($value) ? (int) $value : $this->defaultInt($definition),
            SettingDefinition::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOL),
            default => is_scalar($value) ? trim((string) $value) : '',
        };
    }

    private function normalize(SettingDefinition $definition, mixed $value): string|int|bool
    {
        return match ($definition->type) {
            SettingDefinition::TYPE_INT => $this->clamp($definition, is_numeric($value) ? (int) $value : $this->defaultInt($definition)),
            default => $this->cast($definition, $value),
        };
    }

    private function defaultInt(SettingDefinition $definition): int
    {
        $default = $definition->defaultValue();

        return is_numeric($default) ? (int) $default : 0;
    }

    private function clamp(SettingDefinition $definition, int $value): int
    {
        if ($definition->min !== null) {
            $value = max($definition->min, $value);
        }

        if ($definition->max !== null) {
            $value = min($definition->max, $value);
        }

        return $value;
    }
}
