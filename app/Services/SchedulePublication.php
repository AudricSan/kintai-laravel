<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

use Illuminate\Container\Container;
use App\Models\Shift;
use App\Repositories\StoreRepositoryInterface;

/**
 * Publication du planning par magasin. Quand un magasin l'active (stores.schedule_publication), seuls les shifts datés
 * jusqu'à stores.schedule_published_until sont visibles des employés : les suivants sont un brouillon que le manager
 * prépare sans prévenir personne, puis publie d'un coup (une notification par employé au lieu d'une par shift).
 * Magasin sans publication : tout est publié, comme avant.
 *
 * Les vues de gestion (shifts.update sur le magasin) voient toujours les brouillons ; les vues employé, le flux iCal,
 * la synchronisation d'agenda, l'API en libre-service et les bundles (fonction shift_is_published()) les écartent.
 */
final class SchedulePublication
{
    /** Durée de vie des réglages lus : courte, pour qu'un processus long (file d'attente) voie une publication faite ailleurs. */
    private const SETTINGS_TTL = 10;

    /** @var array<int, array{enabled: bool, until: ?string, read_at: int}> réglages lus, par magasin */
    private array $settings = [];

    public function __construct(
        private readonly StoreRepositoryInterface $stores,
        private readonly ?NotificationService $notifs = null,
    ) {}

    /** Instance du conteneur, ou null hors application (scripts, tests sans conteneur). */
    public static function current(): ?self
    {
        try {
            return Container::getInstance()->make(self::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Raccourci des vues employé : shifts visibles des employés, ou tous hors application.
     *
     * @param list<array<string, mixed>> $shifts
     * @return list<array<string, mixed>>
     */
    public static function staffView(array $shifts): array
    {
        return self::current()?->visibleToStaff($shifts) ?? array_values($shifts);
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->settingsOf($storeId)['enabled'];
    }

    /** Dernier jour publié (YYYY-MM-DD) ; null : rien de publié, ou publication désactivée (voir isEnabled()). */
    public function publishedUntil(int $storeId): ?string
    {
        return $this->settingsOf($storeId)['until'];
    }

    /** Le jour est-il visible des employés de ce magasin ? */
    public function isDatePublished(int $storeId, string $date): bool
    {
        $settings = $this->settingsOf($storeId);
        if (!$settings['enabled']) {
            return true;
        }
        return $settings['until'] !== null && $date !== '' && $date <= $settings['until'];
    }

    /** @param array<string, mixed> $shift */
    public function isPublished(array $shift): bool
    {
        return $this->isDatePublished((int) ($shift['store_id'] ?? 0), (string) ($shift['shift_date'] ?? ''));
    }

    /**
     * Shifts visibles des employés, dans l'ordre reçu.
     *
     * @param list<array<string, mixed>> $shifts
     * @return list<array<string, mixed>>
     */
    public function visibleToStaff(array $shifts): array
    {
        return array_values(array_filter($shifts, fn(array $s): bool => $this->isPublished($s)));
    }

    /**
     * Active ou désactive la publication d'un magasin. À l'activation, tout ce qui est déjà planifié reste publié
     * (jusqu'au dernier shift existant, au moins jusqu'à aujourd'hui) : aucun employé ne voit un shift disparaître.
     */
    public function configure(int $storeId, bool $enabled): void
    {
        $settings = $this->settingsOf($storeId);
        if ($settings['enabled'] === $enabled) {
            return;
        }
        $data = ['id' => $storeId, 'schedule_publication' => $enabled ? 1 : 0];
        if ($enabled) {
            $last = '';
            foreach (Shift::findByStore($storeId) as $s) {
                if (empty($s['deleted_at']) && (string) ($s['shift_date'] ?? '') > $last) {
                    $last = (string) $s['shift_date'];
                }
            }
            $data['schedule_published_until'] = max($last, $settings['until'] ?? '', date('Y-m-d'));
        }
        $this->stores->save($data);
        unset($this->settings[$storeId]);
    }

    /**
     * Publie le planning d'un magasin jusqu'au $until inclus : les employés voient les shifts devenus visibles, et
     * chacun reçoit UNE notification qui les résume. Repousser la date ne republie rien ; une date antérieure à la
     * publication actuelle est refusée (dépublier ferait disparaître des shifts déjà annoncés).
     *
     * @return array{published: int, notified: int} shifts devenus visibles, employés prévenus
     */
    public function publish(int $storeId, string $until): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            throw new \InvalidArgumentException('publish : date invalide « ' . $until . ' ».');
        }
        $settings = $this->settingsOf($storeId);
        if (!$settings['enabled']) {
            throw new \LogicException('publish : la publication du planning est désactivée pour le magasin ' . $storeId . '.');
        }
        $from = $settings['until'];
        if ($from !== null && $until <= $from) {
            return ['published' => 0, 'notified' => 0];
        }

        $newlyVisible = [];
        foreach (Shift::findByStore($storeId) as $s) {
            $date = (string) ($s['shift_date'] ?? '');
            if (!empty($s['deleted_at']) || $date > $until || ($from !== null && $date <= $from)) {
                continue;
            }
            $newlyVisible[] = $s;
        }

        $this->stores->save(['id' => $storeId, 'schedule_published_until' => $until]);
        $this->settings[$storeId] = ['enabled' => true, 'until' => $until, 'read_at' => time()];

        // Agendas externes : les shifts sortis du brouillon partent maintenant (ils étaient écartés jusqu'ici).
        $calendar = $this->calendarSync();
        $byUser = [];
        foreach ($newlyVisible as $s) {
            $uid = (int) ($s['user_id'] ?? 0);
            if ($uid > 0 && empty($s['is_open'])) {
                $byUser[$uid][] = $s;
            }
            if ($calendar !== null) {
                try {
                    $calendar->shiftChanged('saved', $s, null);
                } catch (\Throwable $e) {
                    Log::warning('schedule_publish_calendar_failed', ['shift_id' => $s['id'] ?? null, 'error' => $e->getMessage()]);
                }
            }
        }

        $notified = 0;
        if ($this->notifs !== null) {
            $store = $this->stores->findById($storeId);
            foreach ($byUser as $uid => $own) {
                usort($own, fn(array $a, array $b): int => strcmp((string) $a['shift_date'], (string) $b['shift_date']));
                try {
                    $this->notifs->notify(
                        $uid,
                        'schedule_published',
                        'notif_schedule_published_body',
                        ['count' => (string) count($own), 'until' => $until, 'store' => (string) ($store['name'] ?? '')],
                        null,
                        '/employee/shifts/day?start=' . $own[0]['shift_date'] . '&view=week',
                    );
                    $notified++;
                } catch (\Throwable $e) {
                    Log::warning('schedule_publish_notify_failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
                }
            }
        }

        return ['published' => count($newlyVisible), 'notified' => $notified];
    }

    /** Oublie les réglages lus (après une écriture faite ailleurs, en test). */
    public function forget(): void
    {
        $this->settings = [];
    }

    /** @return array{enabled: bool, until: ?string, read_at: int} */
    private function settingsOf(int $storeId): array
    {
        if (!isset($this->settings[$storeId]) || time() - $this->settings[$storeId]['read_at'] > self::SETTINGS_TTL) {
            $store = $storeId > 0 ? $this->stores->findById($storeId) : null;
            $until = (string) ($store['schedule_published_until'] ?? '');
            $this->settings[$storeId] = [
                'enabled' => !empty($store['schedule_publication']),
                'until'   => $until !== '' ? substr($until, 0, 10) : null,
                'read_at' => time(),
            ];
        }
        return $this->settings[$storeId];
    }

    private function calendarSync(): ?CalendarSyncService
    {
        try {
            $container = Container::getInstance();
            return $container->has(CalendarSyncService::class) || class_exists(CalendarSyncService::class)
                ? $container->make(CalendarSyncService::class)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
