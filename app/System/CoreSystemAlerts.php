<?php

namespace App\System;

use App\License\LicenseState;
use App\Models\Store;
use App\Models\User;
use App\Push\FcmClient;
use App\Push\VapidKeys;
use App\Settings\AppSettings;
use App\Update\UpdateChecker;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\System\SystemAlert;

/**
 * Détecteurs d'alertes système du Core, tous locaux (aucun appel réseau : ils tournent aussi pendant la visite d'un Owner).
 * L'alerte de mise à jour du Core lit le cache de `UpdateChecker`, rafraîchi par la commande planifiée `kintai:update:refresh`.
 * Les alertes de licence lisent l'état local de `LicenseState` (jamais le réseau). Celles des bundles restent à venir.
 */
final class CoreSystemAlerts
{
    /** Une sauvegarde automatique plus vieille que ça (en jours) est considérée comme manquante. */
    public const BACKUP_STALE_DAYS = 2;

    /** Longueur maximale du message d'échec de sauvegarde repris dans l'alerte. */
    private const MESSAGE_MAX = 300;

    /** Corps de notification (clés de traduction) de chaque type. */
    public const BODIES = [
        'core_update_available' => 'Kintai :version is available (installed: :current).',
        'migration_pending' => 'Database migrations are waiting to be applied (:count). Update the database to finish the update.',
        'backup_failed' => 'The last automatic backup failed: :message',
        'backup_stale' => 'No automatic backup has succeeded for more than :days days.',
        'channel_broken' => 'A notification channel is not working: :problems.',
        'license_problem' => 'The license is not valid: :problem. Kintai runs on the free tier until it is fixed.',
        'license_expiring' => 'The license expires on :date (in :days days).',
        'license_quota' => 'The license limit is reached: :quotas. Upgrade your license to add more.',
    ];

    /** Libellés des problèmes de canal (clés de traduction). */
    public const CHANNEL_PROBLEMS = [
        'web_push_keys' => 'Web Push is used but its keys are missing or incomplete',
        'web_push_extensions' => 'Web Push needs PHP extensions that are not loaded',
        'fcm' => 'Mobile push (FCM) is enabled but its configuration is missing or unreadable',
        'email_public_url' => 'Emails are configured but the public URL is not set',
    ];

    /** Libellés des problèmes de licence (clés de traduction), par état de `LicenseStatus`. */
    public const LICENSE_PROBLEMS = [
        'expired' => 'the license has expired',
        'revoked' => 'the license was revoked',
        'suspended' => 'the license is suspended',
        'refused' => 'the license server refused the license',
        'unreachable' => 'the license server could not be reached and the last check has expired',
        'product_key_rejected' => 'the license server refuses the product key and the last check has expired',
    ];

    /** La licence qui expire dans ce nombre de jours (ou moins) déclenche une alerte. */
    public const LICENSE_EXPIRING_DAYS = 14;

    public function __construct(private readonly AppSettings $settings) {}

    public static function registerIn(SystemAlerts $alerts): void
    {
        $alerts->register('core_update_available', 1, fn (): ?SystemAlert => app(self::class)->coreUpdateAvailable());
        $alerts->register('migration_pending', 2, fn (): ?SystemAlert => app(self::class)->migrationPending());
        // Identifiants de référence : 1 mise à jour, 2 migrations, 3 licence (problème), 4-5 sauvegardes, 6 canaux, 7 licence (échéance).
        $alerts->register('license_problem', 3, fn (): ?SystemAlert => app(self::class)->licenseProblem());
        $alerts->register('backup_failed', 4, fn (): ?SystemAlert => app(self::class)->backupFailed());
        $alerts->register('backup_stale', 5, fn (): ?SystemAlert => app(self::class)->backupStale());
        $alerts->register('channel_broken', 6, fn (): ?SystemAlert => app(self::class)->channelBroken());
        $alerts->register('license_expiring', 7, fn (): ?SystemAlert => app(self::class)->licenseExpiring());
        $alerts->register('license_quota', 8, fn (): ?SystemAlert => app(self::class)->licenseQuota());
    }

    /**
     * Release plus récente que la version installée, d'après le cache seul (jamais d'appel réseau).
     */
    public function coreUpdateAvailable(): ?SystemAlert
    {
        $checker = app(UpdateChecker::class);
        $cached = $checker->cached($this->settings->updateChannel());
        $release = $cached['release'] ?? null;

        if ($release === null || ! $checker->isNewer($release)) {
            return null;
        }

        $version = (string) $release->version;

        return new SystemAlert($version, self::BODIES['core_update_available'], ['version' => $version, 'current' => (string) config('kintai.version')], route('updates.index', [], false));
    }

    /**
     * Migrations (du Core et des bundles) qui n'ont pas encore été appliquées.
     */
    public function migrationPending(): ?SystemAlert
    {
        $pending = $this->pendingMigrations();

        if ($pending === []) {
            return null;
        }

        return new SystemAlert(md5(implode('|', $pending)), self::BODIES['migration_pending'], ['count' => count($pending)]);
    }

    /**
     * Dernier échec de la sauvegarde automatique (effacé par le succès suivant).
     */
    public function backupFailed(): ?SystemAlert
    {
        $failure = $this->settings->backupLastFailure();

        if ($failure === '') {
            return null;
        }

        return new SystemAlert($failure, self::BODIES['backup_failed'], ['message' => mb_substr($failure, 0, self::MESSAGE_MAX)], route('backups.index', [], false));
    }

    /**
     * Sauvegarde automatique activée, mais aucune réussie depuis plus de deux jours (ou jamais).
     */
    public function backupStale(): ?SystemAlert
    {
        if (! $this->settings->backupAutoEnabled()) {
            return null;
        }

        $last = $this->settings->backupLastSuccessAt();

        if ($last !== null && $last->greaterThanOrEqualTo(now()->subDays(self::BACKUP_STALE_DAYS))) {
            return null;
        }

        return new SystemAlert($last?->toIso8601String() ?? 'none', self::BODIES['backup_stale'], ['days' => self::BACKUP_STALE_DAYS], route('backups.index', [], false));
    }

    /**
     * Canal de notification mal configuré :
     * - Web Push : clés VAPID incomplètes ou absentes alors que des navigateurs sont abonnés, ou extensions PHP manquantes
     *   dès que le Web Push est utilisé (clés saisies ou abonnements) ;
     * - FCM : activé sans projet ni fichier de compte de service lisible ;
     * - e-mail : transport réel configuré, mais aucune URL publique exploitable (les liens des mails seraient faux).
     */
    public function channelBroken(): ?SystemAlert
    {
        $problems = $this->channelProblems();

        if ($problems === []) {
            return null;
        }

        return new SystemAlert(
            implode(',', $problems),
            self::BODIES['channel_broken'],
            ['problems' => implode('; ', array_map(fn (string $problem): string => __(self::CHANNEL_PROBLEMS[$problem]), $problems))],
            route('settings.app', [], false),
        );
    }

    /**
     * Licence refusée, expirée, suspendue, révoquée, ou dont le dernier jeton a expiré hors ligne : l'instance est au palier gratuit.
     */
    public function licenseProblem(): ?SystemAlert
    {
        $status = app(LicenseState::class)->status();

        if (! $status->isProblem()) {
            return null;
        }

        $problem = __(self::LICENSE_PROBLEMS[$status->productKeyRejected() && $status->state === 'unreachable' ? 'product_key_rejected' : $status->state] ?? self::LICENSE_PROBLEMS['refused']);

        return new SystemAlert($status->state.':'.($status->refusal ?? ''), self::BODIES['license_problem'], ['problem' => $problem], route('license.index', [], false));
    }

    /**
     * Licence valable qui prend fin dans 14 jours ou moins.
     */
    public function licenseExpiring(): ?SystemAlert
    {
        $status = app(LicenseState::class)->status();
        $expires = $status->grantsRights() ? $status->expiresAt() : null;

        if ($expires === null || $expires->isPast() || $expires->greaterThan(now()->addDays(self::LICENSE_EXPIRING_DAYS))) {
            return null;
        }

        $date = $expires->format('Y-m-d');

        return new SystemAlert($date, self::BODIES['license_expiring'], ['date' => $date, 'days' => max(0, (int) ceil(now()->diffInDays($expires, false)))], route('license.index', [], false));
    }

    /**
     * Magasins ou employés au plafond (ou au-delà, après un retour au palier gratuit) de la licence. Jamais bloquant :
     * seule la création est refusée. L'identifiant change avec les plafonds atteints, donc un nouveau plafond renotifie.
     */
    public function licenseQuota(): ?SystemAlert
    {
        $state = app(LicenseState::class);
        $reached = [];
        $quotas = [];

        $stores = $state->limit('max_stores');

        if ($stores !== null && Store::query()->count() >= $stores) {
            $reached[] = 'stores:'.$stores;
            $quotas[] = __(':max stores', ['max' => $stores]);
        }

        $employees = $state->limit('max_employees');

        if ($employees !== null && User::query()->count() >= $employees) {
            $reached[] = 'employees:'.$employees;
            $quotas[] = __(':max employees', ['max' => $employees]);
        }

        if ($reached === []) {
            return null;
        }

        return new SystemAlert(implode(',', $reached), self::BODIES['license_quota'], ['quotas' => implode(', ', $quotas)], route('license.index', [], false));
    }

    /**
     * @return list<string> Clés de `CHANNEL_PROBLEMS`.
     */
    public function channelProblems(): array
    {
        $problems = [];
        $vapid = app(VapidKeys::class);
        $hasPublic = $vapid->publicKey() !== null;
        $hasPrivate = $vapid->privateKey() !== null;
        $subscribed = DB::table('web_push_subscriptions')->exists();

        if ($hasPublic !== $hasPrivate || (! $hasPublic && $subscribed)) {
            $problems[] = 'web_push_keys';
        }

        if (($hasPublic || $hasPrivate || $subscribed) && VapidKeys::missingExtensions() !== []) {
            $problems[] = 'web_push_extensions';
        }

        $fcm = app(FcmClient::class);

        if ((bool) config('kintai.fcm.enabled') && (! $fcm->isEnabled() || ! $fcm->hasReadableCredentials())) {
            $problems[] = 'fcm';
        }

        if ($this->mailConfigured() && ! $this->publicUrlKnown()) {
            $problems[] = 'email_public_url';
        }

        return $problems;
    }

    /**
     * @return list<string> Noms des migrations non appliquées, triés.
     */
    private function pendingMigrations(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        $files = array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        $pending = array_values(array_diff($files, $ran));
        sort($pending);

        return $pending;
    }

    /** Un transport qui envoie vraiment (ni `log`, ni `array`) est-il choisi ? */
    private function mailConfigured(): bool
    {
        return ! in_array((string) config('mail.default'), ['', 'log', 'array'], true);
    }

    /** L'Owner a-t-il réglé l'URL publique, ou `APP_URL` désigne-t-elle autre chose qu'une machine locale ? */
    private function publicUrlKnown(): bool
    {
        if ($this->settings->publicUrl() !== '') {
            return true;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host !== '' && $host !== 'localhost' && $host !== '::1' && $host !== '[::1]' && ! str_starts_with($host, '127.');
    }
}
