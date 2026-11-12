<?php

namespace App\System;

use App\Push\FcmClient;
use App\Push\VapidKeys;
use App\Settings\AppSettings;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\System\SystemAlert;

/**
 * Détecteurs d'alertes système du Core, tous locaux (aucun appel réseau : ils tournent aussi pendant la visite d'un Owner).
 * Les alertes de mise à jour, de bundles et de licence arrivent avec la phase 6.
 */
final class CoreSystemAlerts
{
    /** Une sauvegarde automatique plus vieille que ça (en jours) est considérée comme manquante. */
    public const BACKUP_STALE_DAYS = 2;

    /** Longueur maximale du message d'échec de sauvegarde repris dans l'alerte. */
    private const MESSAGE_MAX = 300;

    /** Corps de notification (clés de traduction) de chaque type. */
    public const BODIES = [
        'migration_pending' => 'Database migrations are waiting to be applied (:count). Update the database to finish the update.',
        'backup_failed' => 'The last automatic backup failed: :message',
        'backup_stale' => 'No automatic backup has succeeded for more than :days days.',
        'channel_broken' => 'A notification channel is not working: :problems.',
    ];

    /** Libellés des problèmes de canal (clés de traduction). */
    public const CHANNEL_PROBLEMS = [
        'web_push_keys' => 'Web Push is used but its keys are missing or incomplete',
        'web_push_extensions' => 'Web Push needs PHP extensions that are not loaded',
        'fcm' => 'Mobile push (FCM) is enabled but its configuration is missing or unreadable',
        'email_public_url' => 'Emails are configured but the public URL is not set',
    ];

    public function __construct(private readonly AppSettings $settings) {}

    public static function registerIn(SystemAlerts $alerts): void
    {
        $alerts->register('migration_pending', 2, fn (): ?SystemAlert => app(self::class)->migrationPending());
        $alerts->register('backup_failed', 4, fn (): ?SystemAlert => app(self::class)->backupFailed());
        $alerts->register('backup_stale', 5, fn (): ?SystemAlert => app(self::class)->backupStale());
        $alerts->register('channel_broken', 6, fn (): ?SystemAlert => app(self::class)->channelBroken());
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
