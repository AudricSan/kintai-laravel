<?php

namespace Kintai\Bundles\ShiftClaim;

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Shifts\ShiftReplacer;
use Kintai\Contracts\Shifts\ShiftWarnings;

/**
 * Écritures de la bourse aux shifts, communes aux écrans et à l'API : droits (`ClaimAccess`), état revérifié sous
 * verrou, journal et notifications. L'attribution passe par le remplacement de shifts du Core (`ShiftReplacer`) : le
 * shift ouvert ou cédé part à la corbeille, un nouveau shift de même créneau appartient au candidat retenu. Les garde-fous
 * du planning s'appliquent (congé approuvé bloquant ; chevauchement, indisponibilité, durée en avertissements) : tant que
 * l'approbation n'est pas confirmée, `Kintai\Contracts\Shifts\ShiftWarnings` est levée et rien n'est écrit.
 *
 * Un refus de droit lève `AuthorizationException`, un état qui ne permet pas l'action une `ValidationException`
 * (clé `request`, ou le champ fautif), un shift hors de portée du demandeur une `ModelNotFoundException`.
 */
class ClaimActions
{
    public function __construct(
        private readonly ClaimAccess $access,
        private readonly Authorizer $authorizer,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
        private readonly ShiftReplacer $replacer,
    ) {}

    /**
     * L'employé candidate à un shift de la bourse. Se représenter après un retrait ou un rejet remet la candidature
     * en attente. Les approbateurs du magasin sont prévenus.
     *
     * @throws ModelNotFoundException Shift introuvable ou d'un magasin où l'employé ne peut pas candidater.
     * @throws ValidationException
     */
    public function claim(User $user, int $shiftId, ?string $note): ShiftClaim
    {
        [$claim, $shift] = DB::transaction(function () use ($user, $shiftId, $note): array {
            $shift = Shift::query()->whereNull('replaced_by_id')->lockForUpdate()->find($shiftId);

            // Hors de ses magasins (ou sans la fonctionnalité), un shift n'existe pas pour lui.
            if ($shift === null || ! isset($this->access->memberStores($user)[$shift->store_id])) {
                throw (new ModelNotFoundException)->setModel(Shift::class, [$shiftId]);
            }

            if (! $this->access->isClaimable($shift)) {
                throw ValidationException::withMessages(['shift' => __('This shift is no longer open for applications.')]);
            }

            if ($shift->user_id === $user->id) {
                throw ValidationException::withMessages(['shift' => __('You cannot apply for your own shift.')]);
            }

            if (app(TimeOffChecker::class)->hasApprovedTimeOff($user->id, $shift->shift_date)) {
                throw ValidationException::withMessages(['shift' => __('You have approved time off on this day.')]);
            }

            $existing = ShiftClaim::query()->where('shift_id', $shift->id)->where('user_id', $user->id)->lockForUpdate()->first();

            if ($existing !== null && in_array($existing->status, [ShiftClaim::PENDING, ShiftClaim::APPROVED], true)) {
                throw ValidationException::withMessages(['shift' => __('You already applied for this shift.')]);
            }

            $attributes = ['status' => ShiftClaim::PENDING, 'note' => $this->clean($note), 'resolved_at' => null, 'resolved_by' => null];

            try {
                $claim = $existing ?? new ShiftClaim(['shift_id' => $shift->id, 'user_id' => $user->id, 'store_id' => $shift->store_id]);
                $claim->fill($attributes)->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['shift' => __('You already applied for this shift.')]);
            }

            return [$claim, $shift];
        });

        $this->activity->record('shift_claim.created', $claim, $shift->store_id, ['shift_id' => $shift->id], $user);

        foreach ($this->membersWhere($shift->store_id, [$user->id], 'open_shifts.approve') as $approver) {
            $this->notifier->notify(
                $approver->id, ShiftClaimServiceProvider::SUBMITTED, ':author applied for the shift on :date from :start to :end (:store).',
                ['author' => $user->name] + $this->slot($shift), route('shift-claim.manage', ['highlight' => $shift->id], false), $shift->id,
            );
        }

        return $claim;
    }

    /**
     * L'employé retire sa candidature tant qu'elle est en attente.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function withdraw(User $actor, ShiftClaim $claim): ShiftClaim
    {
        if ($claim->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        $claim = DB::transaction(function () use ($claim): ShiftClaim {
            $claim = ShiftClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! $claim->isPending()) {
                throw ValidationException::withMessages(['request' => __('This application can no longer be withdrawn.')]);
            }

            $claim->status = ShiftClaim::WITHDRAWN;
            $claim->save();

            return $claim;
        });

        $this->activity->record('shift_claim.withdrawn', $claim, $claim->store_id, ['shift_id' => $claim->shift_id], $actor);

        return $claim;
    }

    /**
     * Un gérant retient une candidature : le Core attribue le shift au candidat (remplacement), les autres candidatures
     * en attente sont rejetées, le candidat retenu et les autres sont prévenus. Les avertissements du planning
     * demandent une confirmation (`$force`).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarnings
     */
    public function approve(User $actor, ShiftClaim $claim, bool $force = false): ShiftClaim
    {
        if (! $this->access->canApprove($actor, $claim)) {
            throw new AuthorizationException;
        }

        [$claim, $shift, $replacementId, $rejected] = DB::transaction(function () use ($actor, $claim, $force): array {
            $claim = ShiftClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! $claim->isPending()) {
                throw ValidationException::withMessages(['request' => __('This application has already been handled.')]);
            }

            $shift = Shift::query()->withTrashed()->lockForUpdate()->find($claim->shift_id);

            if ($shift === null || ! $this->access->isClaimable($shift)) {
                throw ValidationException::withMessages(['request' => __('This shift is no longer open for applications.')]);
            }

            $now = Carbon::now();
            $claim->forceFill(['status' => ShiftClaim::APPROVED, 'resolved_at' => $now, 'resolved_by' => $actor->id])->save();

            // Une seule candidature l'emporte : les autres sont rejetées avant l'attribution, dans la même transaction.
            $others = ShiftClaim::query()->where('shift_id', $shift->id)->where('status', ShiftClaim::PENDING)->where('id', '!=', $claim->id)->lockForUpdate()->get();
            foreach ($others as $other) {
                $other->forceFill(['status' => ShiftClaim::REJECTED, 'resolved_at' => $now, 'resolved_by' => $actor->id])->save();
            }

            $replacementId = $this->replacer->replace($actor, $shift->id, [
                'store_id' => $shift->store_id,
                'user_id' => $claim->user_id,
                'shift_date' => $shift->shift_date->toDateString(),
                'start_time' => $shift->localStartsAt->format('H:i'),
                'end_time' => $shift->localEndsAt->format('H:i'),
                'pause_minutes' => $shift->pause_minutes,
                'notes' => $shift->notes,
            ], $force);

            ShiftOffer::query()->where('shift_id', $shift->id)->delete();

            return [$claim, $shift, $replacementId, $others];
        });

        $this->activity->record('shift_claim.approved', $claim, $claim->store_id, ['shift_id' => $shift->id, 'replacement_id' => $replacementId], $actor);

        $slot = $this->slot($shift);
        $this->notifyUser($claim->user_id, $actor, ShiftClaimServiceProvider::APPROVED,
            'Your application for the shift on :date from :start to :end (:store) was approved: the shift is yours.', $slot,
            route('my-shifts.day', ['date' => $slot['date']], false), $replacementId);

        foreach ($rejected as $other) {
            $this->notifyUser($other->user_id, $actor, ShiftClaimServiceProvider::REJECTED,
                'Your application for the shift on :date from :start to :end (:store) was not selected.', $slot,
                route('shift-claim.index', ['highlight' => $shift->id], false), $shift->id);
        }

        return $claim;
    }

    /**
     * Un gérant rejette une candidature en attente.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reject(User $actor, ShiftClaim $claim): ShiftClaim
    {
        if (! $this->access->canApprove($actor, $claim)) {
            throw new AuthorizationException;
        }

        $claim = DB::transaction(function () use ($actor, $claim): ShiftClaim {
            $claim = ShiftClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! $claim->isPending()) {
                throw ValidationException::withMessages(['request' => __('This application has already been handled.')]);
            }

            $claim->forceFill(['status' => ShiftClaim::REJECTED, 'resolved_at' => Carbon::now(), 'resolved_by' => $actor->id])->save();

            return $claim;
        });

        $this->activity->record('shift_claim.rejected', $claim, $claim->store_id, ['shift_id' => $claim->shift_id], $actor);

        $shift = Shift::query()->withTrashed()->find($claim->shift_id);
        if ($shift !== null) {
            $this->notifyUser($claim->user_id, $actor, ShiftClaimServiceProvider::REJECTED,
                'Your application for the shift on :date from :start to :end (:store) was not selected.', $this->slot($shift),
                route('shift-claim.index', ['highlight' => $shift->id], false), $shift->id);
        }

        return $claim;
    }

    /**
     * Un gérant propose à la bourse un shift attribué : son titulaire le garde jusqu'à l'approbation d'un candidat.
     * Les autres membres du magasin sont prévenus.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function offer(User $actor, int $shiftId, ?string $note): ShiftOffer
    {
        $storeId = Shift::query()->whereKey($shiftId)->value('store_id');

        if ($storeId === null) {
            throw ValidationException::withMessages(['shift' => __('This shift no longer exists.')]);
        }

        if (! $this->access->canPublish($actor, (int) $storeId)) {
            throw new AuthorizationException;
        }

        [$offer, $shift] = DB::transaction(function () use ($actor, $shiftId, $note): array {
            $shift = Shift::query()->whereNull('replaced_by_id')->lockForUpdate()->find($shiftId);

            if ($shift === null) {
                throw ValidationException::withMessages(['shift' => __('This shift no longer exists.')]);
            }

            if (! $this->access->featureEnabled($shift->store_id)) {
                throw ValidationException::withMessages(['shift' => __('The open shifts feature is not enabled for this store.')]);
            }

            if ($shift->user_id === null) {
                throw ValidationException::withMessages(['shift' => __('This shift is already open.')]);
            }

            if ($shift->starts_at->lessThanOrEqualTo(Carbon::now())) {
                throw ValidationException::withMessages(['shift' => __('Only upcoming shifts can be offered.')]);
            }

            if (ShiftOffer::query()->where('shift_id', $shift->id)->exists()) {
                throw ValidationException::withMessages(['shift' => __('This shift is already on offer.')]);
            }

            return [ShiftOffer::create(['shift_id' => $shift->id, 'offered_by' => $actor->id, 'note' => $this->clean($note)]), $shift];
        });

        $this->activity->record('shift_claim.published', $shift, $shift->store_id, ['holder_id' => $shift->user_id], $actor);
        $this->announce($shift, $actor->id, [$shift->user_id]);

        return $offer;
    }

    /**
     * Un gérant retire un shift de la bourse : les candidatures en attente sont retirées et leurs auteurs prévenus.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function unoffer(User $actor, int $shiftId): void
    {
        $shift = Shift::query()->withTrashed()->find($shiftId);

        if ($shift === null) {
            throw ValidationException::withMessages(['shift' => __('This shift no longer exists.')]);
        }

        if (! $this->access->canPublish($actor, $shift->store_id)) {
            throw new AuthorizationException;
        }

        $withdrawn = DB::transaction(function () use ($shiftId): int {
            if (ShiftOffer::query()->where('shift_id', $shiftId)->lockForUpdate()->doesntExist()) {
                throw ValidationException::withMessages(['shift' => __('This shift is not on offer.')]);
            }

            return $this->withdrawPending($shiftId, true);
        });

        $this->activity->record('shift_claim.unpublished', $shift, $shift->store_id, ['withdrawn' => $withdrawn], $actor);
    }

    /**
     * Le shift n'est plus dans la bourse (supprimé, remplacé, réaffecté) : ses candidatures en attente sont retirées,
     * leurs auteurs prévenus, et sa proposition éventuelle supprimée.
     */
    public function release(int $shiftId, ?int $actorId): void
    {
        $this->withdrawPending($shiftId, true, $actorId);
    }

    /**
     * Prévient les membres du magasin qu'un shift est ouvert aux candidatures.
     *
     * @param  list<int|null>  $except
     */
    public function announce(Shift $shift, ?int $actorId, array $except = []): void
    {
        if (! $this->access->featureEnabled($shift->store_id) || $shift->starts_at->lessThanOrEqualTo(Carbon::now())) {
            return;
        }

        $skip = array_values(array_filter([$actorId, ...$except], fn ($id): bool => $id !== null));
        $members = $this->members($shift->store_id, $skip);

        if ($members === []) {
            return;
        }

        $this->notifier->notify(
            $members, ShiftClaimServiceProvider::PUBLISHED, 'A shift is open for applications: :date from :start to :end (:store).',
            $this->slot($shift), route('shift-claim.index', ['highlight' => $shift->id], false), $shift->id,
        );
    }

    /**
     * Retire les candidatures en attente d'un shift (leurs auteurs sont prévenus) et, si demandé, supprime sa proposition.
     */
    private function withdrawPending(int $shiftId, bool $dropOffer, ?int $actorId = null): int
    {
        $claims = ShiftClaim::query()->where('shift_id', $shiftId)->where('status', ShiftClaim::PENDING)->lockForUpdate()->get();

        foreach ($claims as $claim) {
            $claim->status = ShiftClaim::WITHDRAWN;
            $claim->save();
        }

        if ($dropOffer) {
            ShiftOffer::query()->where('shift_id', $shiftId)->delete();
        }

        $shift = $claims->isEmpty() ? null : Shift::query()->withTrashed()->find($shiftId);

        if ($shift !== null) {
            foreach ($claims as $claim) {
                if ($claim->user_id === $actorId) {
                    continue;
                }

                $this->notifier->notify(
                    $claim->user_id, ShiftClaimServiceProvider::WITHDRAWN, 'The shift on :date from :start to :end (:store) is no longer open for applications.',
                    $this->slot($shift), route('shift-claim.index', [], false), $shift->id,
                );
            }
        }

        return $claims->count();
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function notifyUser(int $userId, User $actor, string $type, string $body, array $params, string $link, int $referenceId): void
    {
        if ($userId === $actor->id) {
            return;
        }

        $this->notifier->notify($userId, $type, $body, $params, $link, $referenceId);
    }

    /**
     * Membres actifs d'un magasin (comptes actifs) qui ont la permission, hors ceux listés.
     *
     * @param  list<int>  $except
     * @return list<User>
     */
    private function membersWhere(int $storeId, array $except, string $permission): array
    {
        return array_values(array_filter(
            User::query()->whereIn('id', $this->members($storeId, $except))->get()->all(),
            fn (User $user): bool => $this->authorizer->allows($user, $permission, $storeId),
        ));
    }

    /**
     * Identifiants des membres actifs d'un magasin (comptes actifs), hors ceux listés.
     *
     * @param  list<int>  $except
     * @return list<int>
     */
    private function members(int $storeId, array $except): array
    {
        $ids = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->whereNotIn('user_id', $except)->pluck('user_id');

        return User::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Créneau d'un shift pour les notifications.
     *
     * @return array{date: string, start: string, end: string, store: string}
     */
    private function slot(Shift $shift): array
    {
        return [
            'date' => $shift->shift_date->toDateString(),
            'start' => $shift->localStartsAt->format('H:i'),
            'end' => $shift->localEndsAt->format('H:i'),
            'store' => (string) (Store::query()->whereKey($shift->store_id)->value('name') ?? ''),
        ];
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
