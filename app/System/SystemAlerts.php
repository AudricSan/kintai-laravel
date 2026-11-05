<?php

namespace App\System;

use App\Authorization\Authorizer;
use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationInbox;
use App\Settings\AppSettings;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Kintai\Contracts\System\SystemAlert;
use Kintai\Contracts\System\SystemAlertRegistry;
use Throwable;

/**
 * Alertes système adressées aux Owners actifs : migrations en attente, sauvegarde en échec ou trop ancienne, canal de
 * notification mal configuré… Le Core déclare ses détecteurs (`CoreSystemAlerts`), les bundles et la phase des mises à jour
 * ajoutent les leurs avec `register()` :
 *
 *     app(SystemAlerts::class)->register('core_update_available', 1, fn (): ?SystemAlert => ...);
 *
 * Un détecteur rend une `SystemAlert` ou null quand tout va bien. Déduplication : l'état (type => clé de l'alerte) est mémorisé
 * dans les réglages ; une alerte n'est envoyée que si sa clé change, et l'état est effacé (les notifications deviennent
 * lues) quand le problème disparaît, de sorte qu'une réapparition prévient de nouveau. Un détecteur en panne est ignoré
 * sans effacer l'état connu. Le type de notification du même nom doit être déclaré dans `NotificationTypeRegistry`.
 *
 * Deux déclencheurs : la commande planifiée `kintai:system:alerts` (toutes les heures) et `runIfDue()`, appelé quand un Owner
 * charge une page, pour les instances dont le planificateur ne tourne pas.
 */
class SystemAlerts implements SystemAlertRegistry
{
    /** Nature de l'objet désigné par les notifications d'alerte (« lu » automatique à la résolution). */
    public const REFERENCE_TYPE = 'system';

    /** Intervalle minimal entre deux passes déclenchées par une visite. */
    public const MIN_INTERVAL = 3600;

    private const STATE_KEY = 'system.alerts_state';

    private const LOCK_KEY = 'kintai.system_alerts.lock';

    /** @var array<string, array{reference: int, detect: Closure(): ?SystemAlert}> */
    private array $detectors = [];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly Authorizer $authorizer,
        private readonly NotificationInbox $inbox,
    ) {}

    /**
     * @param  int  $referenceId  Identifiant stable du type (nature « system »), pour le « lu » automatique.
     * @param  Closure(): ?SystemAlert  $detect
     */
    public function register(string $type, int $referenceId, Closure $detect): void
    {
        if (isset($this->detectors[$type])) {
            throw new InvalidArgumentException("System alert [{$type}] is already registered.");
        }

        $this->detectors[$type] = ['reference' => $referenceId, 'detect' => $detect];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->detectors);
    }

    /**
     * Passe déclenchée par la visite d'un Owner : au plus une par heure, sans appel réseau (les détecteurs du Core sont locaux).
     *
     * @return list<string> Types notifiés.
     */
    public function runIfDue(int $minIntervalSeconds = self::MIN_INTERVAL): array
    {
        $last = $this->settings->systemAlertsLastRun();
        $now = now()->getTimestamp();

        if ($last > 0 && $now - $last < $minIntervalSeconds) {
            return [];
        }

        // Date posée avant la passe : deux visites simultanées ne la lancent pas deux fois.
        $this->settings->set('system.alerts_last_run', $now);

        return $this->run();
    }

    /**
     * Une passe complète : détecte, notifie les changements d'état, efface les problèmes résolus.
     *
     * @return list<string> Types notifiés pendant cette passe.
     */
    public function run(): array
    {
        $lock = null;

        try {
            $lock = Cache::lock(self::LOCK_KEY, 120);

            if (! $lock->get()) {
                return [];
            }
        } catch (Throwable) {
            $lock = null; // Cache sans verrou : on continue, la déduplication par état limite les doublons.
        }

        try {
            return $this->detectAndNotify();
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return list<string>
     */
    private function detectAndNotify(): array
    {
        $this->settings->set('system.alerts_last_run', now()->getTimestamp());

        $ownerIds = $this->authorizer->activeOwnerIds();

        // Personne à prévenir : l'état n'est pas mémorisé, le premier Owner actif recevra les alertes en cours.
        if ($ownerIds === []) {
            return [];
        }

        $state = $this->loadState();
        $sent = [];

        foreach ($this->detectors as $type => $detector) {
            try {
                $alert = ($detector['detect'])();
            } catch (Throwable $e) {
                // Un détecteur en panne ne bloque pas les autres et n'efface pas l'état connu.
                report($e);

                continue;
            }

            if ($alert === null) {
                if (isset($state[$type])) {
                    foreach (User::query()->whereIn('id', $ownerIds)->get() as $owner) {
                        $this->inbox->markReadFor($owner, self::REFERENCE_TYPE, $detector['reference']);
                    }

                    unset($state[$type]);
                }

                continue;
            }

            if (($state[$type] ?? null) === $alert->key) {
                continue;
            }

            $this->notifyOwners($ownerIds, $type, $detector['reference'], $alert);
            $state[$type] = $alert->key;
            $sent[] = $type;
        }

        $this->settings->set(self::STATE_KEY, (string) json_encode($state, JSON_UNESCAPED_SLASHES));

        return $sent;
    }

    /**
     * @param  list<int>  $ownerIds
     */
    private function notifyOwners(array $ownerIds, string $type, int $referenceId, SystemAlert $alert): void
    {
        foreach (User::query()->whereIn('id', $ownerIds)->get() as $owner) {
            try {
                $owner->notify(KintaiNotification::make($type, $alert->body, $alert->params, $alert->link, $referenceId));
            } catch (Throwable $e) {
                // Un échec d'envoi (courrier, push) ne doit pas empêcher d'alerter les autres Owners.
                report($e);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadState(): array
    {
        $decoded = json_decode($this->settings->string(self::STATE_KEY), true);

        return is_array($decoded) ? array_map(fn ($value): string => is_scalar($value) ? (string) $value : '', $decoded) : [];
    }
}
