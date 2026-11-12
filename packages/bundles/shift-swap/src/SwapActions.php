<?php

namespace Kintai\Bundles\ShiftSwap;

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Shifts\ShiftExchanger;
use Kintai\Contracts\Shifts\ShiftWarnings;

/**
 * Écritures des échanges, communes aux écrans et à l'API : droits (`SwapAccess`), état revérifié sous verrou, journal et
 * notifications. L'échange lui-même est appliqué par le Core (`ShiftExchanger`, atomique, avec les garde-fous du
 * planning) ; si le Core demande une confirmation, `Kintai\Contracts\Shifts\ShiftWarnings` est levée et rien n'est écrit.
 *
 * Un refus de droit lève `AuthorizationException`, un état qui ne permet pas l'action une `ValidationException`
 * (clé `request`, ou le champ fautif).
 */
class SwapActions
{
    public function __construct(
        private readonly SwapAccess $access,
        private readonly Authorizer $authorizer,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
        private readonly ShiftExchanger $exchanger,
    ) {}

    /**
     * L'employé propose l'un de ses shifts à venir contre un shift à venir d'un collègue du même magasin.
     *
     * @throws AuthorizationException Le shift proposé n'est pas le sien.
     * @throws ValidationException
     */
    public function request(User $requester, int $requesterShiftId, int $targetShiftId, ?string $reason): ShiftSwapRequest
    {
        $swap = DB::transaction(function () use ($requester, $requesterShiftId, $targetShiftId, $reason): ShiftSwapRequest {
            $mine = $this->activeShift($requesterShiftId, 'requester_shift_id');
            $theirs = $this->activeShift($targetShiftId, 'target_shift_id');

            if ($mine->user_id !== $requester->id) {
                throw new AuthorizationException;
            }

            if ($theirs->user_id === null || $theirs->user_id === $requester->id) {
                throw ValidationException::withMessages(['target_shift_id' => __('Choose a shift of a colleague.')]);
            }

            if ($mine->store_id !== $theirs->store_id) {
                throw ValidationException::withMessages(['target_shift_id' => __('Both shifts must belong to the same store.')]);
            }

            if (! isset($this->access->memberStores($requester)[$mine->store_id])) {
                throw ValidationException::withMessages(['requester_shift_id' => __('You cannot swap shifts in this store.')]);
            }

            $colleagueIsMember = StoreMembership::query()->where('store_id', $mine->store_id)->where('user_id', $theirs->user_id)->where('is_active', true)->exists()
                && User::query()->whereKey($theirs->user_id)->where('is_active', true)->exists();

            if (! $colleagueIsMember) {
                throw ValidationException::withMessages(['target_shift_id' => __('This colleague is not an active member of the store.')]);
            }

            foreach ([$mine, $theirs] as $shift) {
                $this->assertUpcoming($shift, 'requester_shift_id');
            }

            $busy = ShiftSwapRequest::query()->where('status', ShiftSwapRequest::PENDING)->where(function ($q) use ($mine, $theirs): void {
                $q->whereIn('requester_shift_id', [$mine->id, $theirs->id])->orWhereIn('target_shift_id', [$mine->id, $theirs->id]);
            })->exists();

            if ($busy) {
                throw ValidationException::withMessages(['requester_shift_id' => __('One of these shifts is already part of a pending swap request.')]);
            }

            return ShiftSwapRequest::create([
                'store_id' => $mine->store_id, 'requester_id' => $requester->id, 'target_user_id' => $theirs->user_id,
                'requester_shift_id' => $mine->id, 'target_shift_id' => $theirs->id,
                'requester_shift_sequence' => $mine->ical_sequence, 'target_shift_sequence' => $theirs->ical_sequence,
                'status' => ShiftSwapRequest::PENDING, 'reason' => $this->clean($reason),
            ]);
        });

        $this->activity->record('swap.created', $swap, $swap->store_id, ['target_id' => $swap->target_user_id], $requester);
        $this->tell($swap->target_user_id, $requester, $swap, ShiftSwapServiceProvider::REQUESTED,
            ':colleague offers a shift swap: their shift :from against your shift :to (:store).', $requester->name);

        return $swap;
    }

    /**
     * Le collègue accepte (la demande attend alors un gérant) ou refuse (la demande est close).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function answer(User $actor, ShiftSwapRequest $swap, bool $accept): ShiftSwapRequest
    {
        if ($swap->target_user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        $swap = DB::transaction(function () use ($swap, $accept): ShiftSwapRequest {
            $swap = $this->lock($swap);

            if (! $swap->awaitsColleague()) {
                throw ValidationException::withMessages(['request' => __('This request has already been handled.')]);
            }

            if ($accept) {
                $this->assertShiftsUnchanged($swap);
                $swap->accepted_at = Carbon::now();
            } else {
                $swap->status = ShiftSwapRequest::REFUSED;
            }

            $swap->save();

            return $swap;
        });

        if ($accept) {
            $this->activity->record('swap.peer_accepted', $swap, $swap->store_id, ['requester_id' => $swap->requester_id], $actor);
            $this->tell($swap->requester_id, $actor, $swap, ShiftSwapServiceProvider::PEER_ACCEPTED,
                ':colleague accepted the swap of your shift :from against :to (:store). It now waits for a manager.', $actor->name);
            $this->tellApprovers($actor, $swap);
        } else {
            $this->activity->record('swap.peer_refused', $swap, $swap->store_id, ['requester_id' => $swap->requester_id], $actor);
            $this->tell($swap->requester_id, $actor, $swap, ShiftSwapServiceProvider::PEER_REFUSED,
                ':colleague declined the swap of your shift :from against :to (:store).', $actor->name);
        }

        return $swap;
    }

    /**
     * Le demandeur retire sa demande tant qu'elle n'est pas décidée.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancel(User $actor, ShiftSwapRequest $swap): ShiftSwapRequest
    {
        if ($swap->requester_id !== $actor->id) {
            throw new AuthorizationException;
        }

        $swap = DB::transaction(function () use ($swap): ShiftSwapRequest {
            $swap = $this->lock($swap);

            if (! $swap->isPending()) {
                throw ValidationException::withMessages(['request' => __('This request can no longer be cancelled.')]);
            }

            $swap->status = ShiftSwapRequest::CANCELLED;
            $swap->save();

            return $swap;
        });

        $this->activity->record('swap.cancelled', $swap, $swap->store_id, ['target_id' => $swap->target_user_id], $actor);
        $this->tell($swap->target_user_id, $actor, $swap, ShiftSwapServiceProvider::CANCELLED,
            ':colleague cancelled the swap request (:from against :to, :store).', $actor->name);

        return $swap;
    }

    /**
     * Un gérant approuve une demande acceptée par le collègue : le Core échange les deux shifts. Les deux employés sont
     * prévenus. Les avertissements du planning demandent une confirmation (`$force`).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarnings
     */
    public function approve(User $actor, ShiftSwapRequest $swap, bool $force = false): ShiftSwapRequest
    {
        if (! $this->access->canApprove($actor, $swap)) {
            throw new AuthorizationException;
        }

        $swap = DB::transaction(function () use ($actor, $swap, $force): ShiftSwapRequest {
            $swap = $this->lock($swap);

            if (! $swap->isPending()) {
                throw ValidationException::withMessages(['request' => __('This request has already been handled.')]);
            }

            if ($swap->accepted_at === null) {
                throw ValidationException::withMessages(['request' => __('The colleague has not accepted this swap yet.')]);
            }

            $this->assertShiftsUnchanged($swap);

            [$forRequesterShift, $forTargetShift] = $this->exchanger->exchange($actor, $swap->requester_shift_id, $swap->target_shift_id, $force);

            $swap->forceFill([
                'status' => ShiftSwapRequest::ACCEPTED, 'approved_by_id' => $actor->id, 'approved_at' => Carbon::now(),
                'applied_requester_shift_id' => $forRequesterShift, 'applied_target_shift_id' => $forTargetShift,
            ])->save();

            return $swap;
        });

        $this->activity->record('swap.approved', $swap, $swap->store_id, ['requester_id' => $swap->requester_id, 'target_id' => $swap->target_user_id], $actor);
        $this->tellBoth($actor, $swap, ShiftSwapServiceProvider::ACCEPTED,
            'The shift swap (:from against :to, :store) was approved and applied to your schedule.');

        return $swap;
    }

    /**
     * Un gérant refuse une demande en attente.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function refuse(User $actor, ShiftSwapRequest $swap): ShiftSwapRequest
    {
        if (! $this->access->canApprove($actor, $swap)) {
            throw new AuthorizationException;
        }

        $swap = DB::transaction(function () use ($actor, $swap): ShiftSwapRequest {
            $swap = $this->lock($swap);

            if (! $swap->isPending()) {
                throw ValidationException::withMessages(['request' => __('This request has already been handled.')]);
            }

            $swap->forceFill(['status' => ShiftSwapRequest::REFUSED, 'approved_by_id' => $actor->id, 'approved_at' => Carbon::now()])->save();

            return $swap;
        });

        $this->activity->record('swap.refused', $swap, $swap->store_id, ['requester_id' => $swap->requester_id], $actor);
        $this->tell($swap->requester_id, $actor, $swap, ShiftSwapServiceProvider::REFUSED, 'The shift swap request (:from against :to, :store) was refused.');

        return $swap;
    }

    /**
     * Un gérant échange d'emblée les shifts de deux employés du même magasin (`swaps.create`), sans demande ni accord
     * préalables : la demande est enregistrée comme appliquée. Les deux employés sont prévenus.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarnings
     */
    public function createApplied(User $actor, int $shiftId, int $otherShiftId, ?string $reason, bool $force = false): ShiftSwapRequest
    {
        $swap = DB::transaction(function () use ($actor, $shiftId, $otherShiftId, $reason, $force): ShiftSwapRequest {
            $first = $this->activeShift($shiftId, 'requester_shift_id');
            $second = $this->activeShift($otherShiftId, 'target_shift_id');

            if (! $this->access->canCreate($actor, $first->store_id) || ! $this->access->canCreate($actor, $second->store_id)) {
                throw new AuthorizationException;
            }

            if ($first->user_id === null || $second->user_id === null || $first->user_id === $second->user_id) {
                throw ValidationException::withMessages(['target_shift_id' => __('Choose the shifts of two different employees.')]);
            }

            if ($first->store_id !== $second->store_id) {
                throw ValidationException::withMessages(['target_shift_id' => __('Both shifts must belong to the same store.')]);
            }

            if (! isset($this->access->storesWith($actor, 'swaps.create')[$first->store_id])) {
                throw ValidationException::withMessages(['requester_shift_id' => __('The swap feature is not enabled for this store.')]);
            }

            foreach ([$first, $second] as $shift) {
                $this->assertUpcoming($shift, 'requester_shift_id');
            }

            $busy = ShiftSwapRequest::query()->where('status', ShiftSwapRequest::PENDING)->where(function ($q) use ($first, $second): void {
                $q->whereIn('requester_shift_id', [$first->id, $second->id])->orWhereIn('target_shift_id', [$first->id, $second->id]);
            })->exists();

            if ($busy) {
                throw ValidationException::withMessages(['requester_shift_id' => __('One of these shifts is already part of a pending swap request.')]);
            }

            [$forFirst, $forSecond] = $this->exchanger->exchange($actor, $first->id, $second->id, $force);

            return ShiftSwapRequest::create([
                'store_id' => $first->store_id, 'requester_id' => $first->user_id, 'target_user_id' => $second->user_id,
                'requester_shift_id' => $first->id, 'target_shift_id' => $second->id,
                'requester_shift_sequence' => $first->ical_sequence, 'target_shift_sequence' => $second->ical_sequence,
                'status' => ShiftSwapRequest::ACCEPTED, 'reason' => $this->clean($reason),
                'accepted_at' => Carbon::now(), 'approved_by_id' => $actor->id, 'approved_at' => Carbon::now(),
                'applied_requester_shift_id' => $forFirst, 'applied_target_shift_id' => $forSecond,
            ]);
        });

        $this->activity->record('swap.created_by_manager', $swap, $swap->store_id, ['requester_id' => $swap->requester_id, 'target_id' => $swap->target_user_id], $actor);
        $this->tellBoth($actor, $swap, ShiftSwapServiceProvider::APPLIED, 'A manager applied a shift swap to your schedule: :from against :to (:store).');

        return $swap;
    }

    /**
     * Corrige le motif d'une demande encore en attente.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function updateReason(User $actor, ShiftSwapRequest $swap, ?string $reason): ShiftSwapRequest
    {
        if (! $this->access->canUpdate($actor, $swap)) {
            throw new AuthorizationException;
        }

        $swap = DB::transaction(function () use ($swap, $reason): ShiftSwapRequest {
            $swap = $this->lock($swap);

            if (! $swap->isPending()) {
                throw ValidationException::withMessages(['request' => __('This request has already been handled.')]);
            }

            $swap->reason = $this->clean($reason);
            $swap->save();

            return $swap;
        });

        $this->activity->record('swap.updated', $swap, $swap->store_id, ['fields' => ['reason']], $actor);

        return $swap;
    }

    /**
     * Supprime une demande. Un échange appliqué est défait par le Core si les deux shifts issus de l'échange sont
     * toujours tels que l'échange les a laissés ; sinon (réaffectés depuis), ils ne sont pas touchés.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(User $actor, ShiftSwapRequest $swap): void
    {
        if (! $this->access->canDelete($actor, $swap)) {
            throw new AuthorizationException;
        }

        [$swap, $restored] = DB::transaction(function () use ($actor, $swap): array {
            $swap = $this->lock($swap);
            $restored = false;

            if ($swap->status === ShiftSwapRequest::ACCEPTED && $swap->applied_requester_shift_id !== null && $swap->applied_target_shift_id !== null) {
                $shifts = Shift::query()->withTrashed()->whereIn('id', [$swap->applied_requester_shift_id, $swap->applied_target_shift_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $held = $shifts->get($swap->applied_requester_shift_id);
                $other = $shifts->get($swap->applied_target_shift_id);

                $stillSwapped = $held !== null && $other !== null
                    && ! $held->trashed() && ! $other->trashed() && $held->replaced_by_id === null && $other->replaced_by_id === null
                    && $held->user_id === $swap->target_user_id && $other->user_id === $swap->requester_id;

                if ($stillSwapped) {
                    // Rétablir un état déjà validé : les avertissements ne demandent pas de nouvelle confirmation.
                    $this->exchanger->exchange($actor, $held->id, $other->id, true);
                    $restored = true;
                }
            }

            $swap->delete();

            return [$swap, $restored];
        });

        $this->activity->recordFor('swap.deleted', ShiftSwapRequest::class, $swap->id, $swap->store_id, [
            'requester_id' => $swap->requester_id, 'target_id' => $swap->target_user_id, 'was' => $swap->status, 'restored' => $restored,
        ], $actor);

        if ($restored) {
            $this->tellBoth($actor, $swap, ShiftSwapServiceProvider::RESTORED, 'The shift swap (:from against :to, :store) was cancelled and your shifts were restored.');
        }
    }

    /**
     * Relit la demande sous verrou (dans la transaction de l'action).
     */
    private function lock(ShiftSwapRequest $swap): ShiftSwapRequest
    {
        return ShiftSwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
    }

    /**
     * Un shift actif (ni supprimé ni remplacé), relu sous verrou.
     *
     * @throws ValidationException
     */
    private function activeShift(int $id, string $field): Shift
    {
        $shift = Shift::query()->whereNull('replaced_by_id')->lockForUpdate()->find($id);

        if ($shift === null) {
            throw ValidationException::withMessages([$field => __('This shift no longer exists.')]);
        }

        return $shift;
    }

    /**
     * @throws ValidationException
     */
    private function assertUpcoming(Shift $shift, string $field): void
    {
        if ($shift->starts_at->lessThanOrEqualTo(Carbon::now())) {
            throw ValidationException::withMessages([$field => __('Only upcoming shifts can be swapped.')]);
        }
    }

    /**
     * Les deux shifts doivent être tels qu'à la demande : actifs, aux mêmes employés, non modifiés, à venir.
     *
     * @throws ValidationException
     */
    private function assertShiftsUnchanged(ShiftSwapRequest $swap): void
    {
        $shifts = Shift::query()->withTrashed()->whereIn('id', [$swap->requester_shift_id, $swap->target_shift_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ([
            [$shifts->get($swap->requester_shift_id), $swap->requester_id, $swap->requester_shift_sequence],
            [$shifts->get($swap->target_shift_id), $swap->target_user_id, $swap->target_shift_sequence],
        ] as [$shift, $userId, $sequence]) {
            if ($shift === null || $shift->trashed() || $shift->replaced_by_id !== null || $shift->user_id !== $userId || $shift->ical_sequence !== $sequence) {
                throw ValidationException::withMessages(['request' => __('One of the shifts changed since the request, so it can no longer be swapped.')]);
            }

            if ($shift->starts_at->lessThanOrEqualTo(Carbon::now())) {
                throw ValidationException::withMessages(['request' => __('Only upcoming shifts can be swapped.')]);
            }
        }
    }

    /**
     * Les gérants du magasin qui approuvent sont prévenus qu'une demande attend leur décision.
     */
    private function tellApprovers(User $actor, ShiftSwapRequest $swap): void
    {
        $members = StoreMembership::query()->where('store_id', $swap->store_id)->where('is_active', true)
            ->whereNotIn('user_id', [$swap->requester_id, $swap->target_user_id])->pluck('user_id');

        foreach (User::query()->whereIn('id', $members)->where('is_active', true)->get() as $approver) {
            if ($this->authorizer->allows($approver, 'swaps.approve', $swap->store_id)) {
                $this->tell($approver->id, $actor, $swap, ShiftSwapServiceProvider::TO_APPROVE,
                    ':colleague accepted a shift swap (:from against :to, :store). Your decision is needed.', $actor->name, true);
            }
        }
    }

    /**
     * Prévient le demandeur et son collègue (sauf l'acteur).
     */
    private function tellBoth(User $actor, ShiftSwapRequest $swap, string $type, string $body): void
    {
        foreach (array_unique([$swap->requester_id, $swap->target_user_id]) as $userId) {
            $this->tell($userId, $actor, $swap, $type, $body);
        }
    }

    private function tell(int $userId, User $actor, ShiftSwapRequest $swap, string $type, string $body, string $colleague = '', bool $manage = false): void
    {
        if ($userId === $actor->id) {
            return;
        }

        $swap->loadMissing(['requesterShift', 'targetShift', 'store']);

        $this->notifier->notify(
            $userId, $type, $body,
            ['colleague' => $colleague, 'from' => ShiftSwapRequest::describe($swap->requesterShift), 'to' => ShiftSwapRequest::describe($swap->targetShift), 'store' => $swap->store->name],
            route($manage ? 'shift-swap.manage' : 'shift-swap.index', ['highlight' => $swap->id], false), $swap->id,
        );
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
