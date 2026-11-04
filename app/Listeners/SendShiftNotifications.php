<?php

namespace App\Listeners;

use App\Events\ShiftCreated;
use App\Events\ShiftDeleted;
use App\Events\ShiftReplaced;
use App\Events\ShiftRestored;
use App\Events\ShiftUpdated;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Notifications\KintaiNotification;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Prévient l'employé concerné quand son planning change, quelle que soit l'origine de l'écriture (écrans, API,
 * bundles : tout passe par les actions des shifts et leurs événements). Jamais de notification à l'auteur
 * lui-même ; un échec d'envoi est journalisé sans défaire l'écriture déjà faite.
 *
 * - création ou restauration d'un shift assigné : « shift_assigned » ; shift ouvert : rien ;
 * - réassignation : « shift_assigned » au nouvel employé et « shift_deleted » à l'ancien ;
 * - même employé, magasin / jour / heures changés : « shift_updated » ; autre changement (notes, pause) : rien ;
 * - suppression : « shift_deleted » ;
 * - remplacement : traité comme une modification de l'ancien shift vers le nouveau.
 */
class SendShiftNotifications
{
    public function created(ShiftCreated $event): void
    {
        $this->assignedFromId($event->shiftId, $event->actorId);
    }

    public function restored(ShiftRestored $event): void
    {
        $this->assignedFromId($event->shiftId, $event->actorId);
    }

    public function updated(ShiftUpdated $event): void
    {
        $shift = Shift::query()->find($event->shiftId);

        if ($shift === null) {
            return;
        }

        if ($event->userChanged) {
            if ($shift->user_id !== null) {
                $this->assigned($shift->user_id, $shift->id, $shift->slot(), $event->actorId);
            }

            if ($event->previousUserId !== null) {
                $this->cancelled($event->previousUserId, $event->previousSlot ?? $shift->slot(), $event->actorId);
            }

            return;
        }

        if ($event->rescheduled && $shift->user_id !== null) {
            $this->updatedSlot($shift->user_id, $shift->id, $shift->slot(), $event->actorId);
        }
    }

    public function deleted(ShiftDeleted $event): void
    {
        $shift = Shift::query()->withTrashed()->find($event->shiftId);

        if ($shift?->user_id !== null) {
            $this->cancelled($shift->user_id, $shift->slot(), $event->actorId);
        }
    }

    public function replaced(ShiftReplaced $event): void
    {
        $old = Shift::query()->withTrashed()->find($event->replacedShiftId);
        $new = Shift::query()->find($event->replacementShiftId);

        if ($old === null || $new === null) {
            return;
        }

        if ($old->user_id === $new->user_id) {
            if ($new->user_id !== null && $old->slot() !== $new->slot()) {
                $this->updatedSlot($new->user_id, $new->id, $new->slot(), $event->actorId);
            }

            return;
        }

        if ($new->user_id !== null) {
            $this->assigned($new->user_id, $new->id, $new->slot(), $event->actorId);
        }

        if ($old->user_id !== null) {
            $this->cancelled($old->user_id, $old->slot(), $event->actorId);
        }
    }

    private function assignedFromId(int $shiftId, ?int $actorId): void
    {
        $shift = Shift::query()->find($shiftId);

        if ($shift?->user_id !== null) {
            $this->assigned($shift->user_id, $shift->id, $shift->slot(), $actorId);
        }
    }

    /**
     * @param  array{store_id: int, date: string, start: string, end: string}  $slot
     */
    private function assigned(int $userId, int $shiftId, array $slot, ?int $actorId): void
    {
        $this->send($userId, 'shift_assigned', 'A shift was added for you on :date from :start to :end (:store).', $slot, $actorId, $shiftId);
    }

    /**
     * @param  array{store_id: int, date: string, start: string, end: string}  $slot
     */
    private function updatedSlot(int $userId, int $shiftId, array $slot, ?int $actorId): void
    {
        $this->send($userId, 'shift_updated', 'Your shift was changed: new slot on :date from :start to :end (:store).', $slot, $actorId, $shiftId);
    }

    /**
     * @param  array{store_id: int, date: string, start: string, end: string}  $slot
     */
    private function cancelled(int $userId, array $slot, ?int $actorId): void
    {
        $this->send($userId, 'shift_deleted', 'Your shift on :date from :start to :end (:store) was removed from your schedule.', $slot, $actorId, null);
    }

    /**
     * @param  array{store_id: int, date: string, start: string, end: string}  $slot
     */
    private function send(int $userId, string $type, string $body, array $slot, ?int $actorId, ?int $shiftId): void
    {
        if ($userId === $actorId) {
            return;
        }

        try {
            $user = User::query()->whereKey($userId)->where('is_active', true)->first();

            if ($user === null) {
                return;
            }

            $date = (string) $slot['date'];

            // Le shift supprimé n'existe plus : la notification désigne le jour (AAAAMMJJ), vu dans « Mes shifts ».
            $reference = $shiftId ?? (int) CarbonImmutable::parse($date)->format('Ymd');

            $user->notify(KintaiNotification::make($type, $body, [
                'date' => $date,
                'start' => (string) $slot['start'],
                'end' => (string) $slot['end'],
                'store' => (string) (Store::query()->whereKey($slot['store_id'])->value('name') ?? ''),
            ], route('my-shifts.day', ['date' => $date], false), $reference));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
