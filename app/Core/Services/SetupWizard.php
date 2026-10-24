<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\LicenseServiceProvider;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/**
 * Assistant de configuration qui suit l'installateur (public/install.php). Étapes, dans l'ordre :
 * Organisation → Premier magasin → E-mail → Notifications → Exploitation → Bundles → Licence → Récapitulatif.
 *
 * Chaque étape peut être faite, passée ou laissée à faire, et revisitée ; l'état (étapes faites ou passées) vit dans
 * app_settings. « Bundles » (choix limité par la licence, téléchargés depuis le catalogue sur le canal du Core et activés)
 * précède « Licence » (facultative ; sans licence, plan gratuit à 4 bundles) pour que le quota ne bride pas le choix.
 * Tant que l'assistant n'est pas terminé ou explicitement passé, l'Owner voit un bandeau qui y ramène.
 *
 * Une instance installée avant l'assistant n'a pas la clé et n'est pas concernée ; une instance en cours de l'ancien
 * assistant (Licence puis Bundles) garde sa progression : les nouvelles étapes lui restent proposées en amont.
 */
final class SetupWizard
{
    public const PENDING_KEY = 'setup_wizard_pending';
    /** Ancienne clé (assistant en deux étapes) : lue seulement pour reprendre une progression existante. */
    public const STEP_KEY = 'setup_wizard_step';
    public const DONE_KEY = 'setup_wizard_done';
    /** Bundles choisis au-delà du quota, mémorisés (JSON, dans l'ordre) et installés dès qu'une licence les permet. */
    public const PENDING_BUNDLES_KEY = 'setup_pending_bundles';

    public const STEP_ORGANISATION = 'organisation';
    public const STEP_STORE = 'store';
    public const STEP_MAIL = 'mail';
    public const STEP_NOTIFICATIONS = 'notifications';
    public const STEP_OPERATIONS = 'operations';
    public const STEP_BUNDLES = 'bundles';
    public const STEP_LICENSE = 'license';
    public const STEP_RECAP = 'recap';

    /** Ordre des étapes. Le récapitulatif est toujours la dernière. */
    public const STEPS = [
        self::STEP_ORGANISATION,
        self::STEP_STORE,
        self::STEP_MAIL,
        self::STEP_NOTIFICATIONS,
        self::STEP_OPERATIONS,
        self::STEP_BUNDLES,
        self::STEP_LICENSE,
        self::STEP_RECAP,
    ];

    /** Étapes qu'on peut passer sans les faire. Le récapitulatif n'en est pas une. */
    public const SKIPPABLE = [
        self::STEP_ORGANISATION, self::STEP_STORE, self::STEP_MAIL, self::STEP_NOTIFICATIONS,
        self::STEP_OPERATIONS, self::STEP_BUNDLES, self::STEP_LICENSE,
    ];

    public const DONE = 'done';
    public const SKIPPED = 'skipped';

    /**
     * @param \Closure $catalog   fn(string $channel): list<array{slug: string, name: string, description: string,
     *                            version: string, repository_url: string, registry_url: string}> — bundles proposés
     * @param \Closure $installer fn(array $entry): ?string — installe un bundle du catalogue ; null si réussi, sinon
     *                            le motif de l'échec
     */
    public function __construct(
        private readonly AppSettingsRepositoryInterface $settings,
        private readonly \Closure $catalog,
        private readonly \Closure $installer,
        private readonly BundleActivationService $activation,
        private readonly PlanLimitService $planLimits,
    ) {}

    /**
     * Appelé par l'installateur sur une instance neuve : aucun bundle actif, assistant à la première étape, bundles
     * suivis sur le même canal que le Core.
     */
    public static function start(AppSettingsRepositoryInterface $settings, string $coreChannel): void
    {
        $settings->setMany([
            LicenseServiceProvider::SETTINGS_KEY => '[]',
            'bundle_update_channel'              => $coreChannel,
            self::PENDING_KEY                    => '1',
            self::DONE_KEY                       => '{}',
        ]);
    }

    public function isPending(): bool
    {
        return $this->settings->get(self::PENDING_KEY) === '1';
    }

    /**
     * État de chaque étape : DONE, SKIPPED ou null (à faire). Le récapitulatif n'a pas d'état.
     *
     * @return array<string, string|null>
     */
    public function progress(): array
    {
        $raw = $this->settings->get(self::DONE_KEY);
        $saved = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($saved)) {
            // Assistant en deux étapes : Licence faite si l'ancienne clé est déjà passée à « bundles ».
            $saved = $this->settings->get(self::STEP_KEY) === self::STEP_BUNDLES ? [self::STEP_LICENSE => self::DONE] : [];
        }
        $progress = [];
        foreach (self::SKIPPABLE as $step) {
            $state = $saved[$step] ?? null;
            $progress[$step] = in_array($state, [self::DONE, self::SKIPPED], true) ? $state : null;
        }
        return $progress;
    }

    /** Étape à afficher : la première à faire, sinon le récapitulatif. */
    public function step(): string
    {
        foreach ($this->progress() as $step => $state) {
            if ($state === null) {
                return $step;
            }
        }
        return self::STEP_RECAP;
    }

    public static function isStep(string $step): bool
    {
        return in_array($step, self::STEPS, true);
    }

    /** Numéro (à partir de 1) d'une étape dans l'ordre de l'assistant. */
    public static function position(string $step): int
    {
        return (int) array_search($step, self::STEPS, true) + 1;
    }

    /** Étape qui suit $step dans l'ordre, ou le récapitulatif. */
    public static function nextStep(string $step): string
    {
        $index = array_search($step, self::STEPS, true);
        return self::STEPS[($index === false ? count(self::STEPS) - 2 : $index) + 1] ?? self::STEP_RECAP;
    }

    /** Étape qui précède $step, ou null pour la première. */
    public static function previousStep(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);
        return $index === false || $index === 0 ? null : self::STEPS[$index - 1];
    }

    /** Marque une étape faite (ou passée). Les étapes inconnues et le récapitulatif sont ignorés. */
    public function mark(string $step, bool $skipped = false): void
    {
        if (!in_array($step, self::SKIPPABLE, true)) {
            return;
        }
        $progress = array_filter($this->progress(), static fn($s) => $s !== null);
        $progress[$step] = $skipped ? self::SKIPPED : self::DONE;
        $this->settings->set(self::DONE_KEY, json_encode($progress, JSON_THROW_ON_ERROR));
    }

    /** Étape « Licence » terminée (licence activée ou passée). */
    public function licenseDone(): void
    {
        $this->mark(self::STEP_LICENSE);
    }

    /** Assistant terminé (ou passé) : plus de bandeau. */
    public function finish(): void
    {
        $this->settings->set(self::PENDING_KEY, '0');
    }

    /** Place restante dans le quota (nombre de bundles encore activables), ou null si illimité. */
    public function room(): ?int
    {
        return $this->activation->room();
    }

    /** Nombre de bundles que la licence permet d'activer, ou null si illimité. */
    public function maxBundles(): ?int
    {
        return $this->planLimits->maxActiveBundles();
    }

    /** @return list<array{slug: string, name: string, description: string, version: string, repository_url: string, registry_url: string}> */
    public function availableBundles(string $channel): array
    {
        return ($this->catalog)($channel);
    }

    /**
     * Bundles choisis mais pas encore installés faute de licence, dans l'ordre de priorité.
     *
     * @return list<string>
     */
    public function pendingBundles(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::PENDING_BUNDLES_KEY), true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Une licence vient d'être activée : installe, dans l'ordre mémorisé et dans la limite de la nouvelle place, les
     * bundles qui attendaient. Ceux qui ne passent pas encore, ou dont le téléchargement échoue, restent en attente.
     *
     * @return array{error: ?string, installed: list<string>, failed: array<string, string>, inactive: list<string>, pending: list<string>}
     */
    public function installPending(string $channel): array
    {
        $pending = $this->pendingBundles();
        $empty = ['error' => null, 'installed' => [], 'failed' => [], 'inactive' => [], 'pending' => $pending];
        if ($pending === []) {
            return $empty;
        }
        $room = $this->activation->room();
        if ($room === 0) {
            return $empty;
        }

        $batch = $room === null ? $pending : array_slice($pending, 0, $room);
        $result = $this->install($batch, $channel, false);
        $remaining = array_values(array_diff($pending, $result['installed']));
        $this->settings->set(self::PENDING_BUNDLES_KEY, json_encode($remaining, JSON_THROW_ON_ERROR));
        $result['pending'] = $remaining;
        return $result;
    }

    /**
     * Installe et active les bundles choisis, dans l'ordre donné. Un choix hors catalogue est refusé en entier. Ceux qui
     * dépassent la place restante du quota ne sont pas installés : ils sont mémorisés « en attente de licence »
     * ($remember) et rapportés dans `pending`. Un échec d'installation n'empêche pas les autres (il est rapporté).
     *
     * @param list<string> $slugs
     * @return array{error: ?string, installed: list<string>, failed: array<string, string>, inactive: list<string>, pending: list<string>}
     */
    public function install(array $slugs, string $channel, bool $remember = true): array
    {
        $result = ['error' => null, 'installed' => [], 'failed' => [], 'inactive' => [], 'pending' => []];
        $bySlug = [];
        foreach ($this->availableBundles($channel) as $entry) {
            $bySlug[$entry['slug']] = $entry;
        }
        $slugs = array_values(array_unique($slugs));
        if (array_diff($slugs, array_keys($bySlug)) !== []) {
            $result['error'] = 'setup_bundles_unknown';
            return $result;
        }
        $room = $this->activation->room();
        if ($room !== null && count($slugs) > $room) {
            $result['pending'] = array_slice($slugs, $room);
            $slugs = array_slice($slugs, 0, $room);
            if ($remember) {
                $this->settings->set(self::PENDING_BUNDLES_KEY, json_encode($result['pending'], JSON_THROW_ON_ERROR));
            }
        } elseif ($remember) {
            $this->settings->set(self::PENDING_BUNDLES_KEY, '[]');
        }

        foreach ($slugs as $slug) {
            try {
                $failure = ($this->installer)($bySlug[$slug]);
            } catch (\Throwable $e) {
                $failure = $e->getMessage();
            }
            if ($failure !== null) {
                $result['failed'][$slug] = $failure;
                continue;
            }
            $result['installed'][] = $slug;
            if ($this->activation->activateIfRoom($slug) === BundleActivationService::QUOTA_FULL) {
                $result['inactive'][] = $slug;
            }
        }
        return $result;
    }
}
