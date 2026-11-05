<?php

namespace Kintai\Bundles\Timeoff;

use App\Authorization\Authorizer;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;

/**
 * Écritures des congés, communes aux écrans et à l'API : droits (`TimeoffAccess`), journal et notifications. Les champs
 * arrivent déjà validés (type, dates `Y-m-d`, fin >= début). Un refus lève `AuthorizationException`, un état qui ne
 * permet pas l'action une `ValidationException` (clé `request`).
 */
class TimeoffActions
{
    public function __construct(
        private readonly TimeoffAccess $access,
        private readonly Authorizer $authorizer,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
    ) {}

    /**
     * L'employé dépose une demande (en attente) pour lui-même ; les approbateurs du magasin sont prévenus.
     *
     * @throws ValidationException Magasin où l'employé ne peut pas déposer.
     */
    public function submit(User $employee, int $storeId, string $type, string $start, string $end, ?string $reason): TimeoffRequest
    {
        if (! isset($this->access->memberStores($employee)[$storeId])) {
            throw ValidationException::withMessages(['store_id' => __('You cannot request time off in this store.')]);
        }

        $request = TimeoffRequest::create([
            'user_id' => $employee->id, 'store_id' => $storeId, 'type' => $type, 'status' => TimeoffRequest::PENDING,
            'start_date' => $start, 'end_date' => $end, 'reason' => $this->clean($reason),
        ]);

        $this->activity->record('timeoff.created', $request, $storeId, $this->summary($request), $employee);

        $members = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->where('user_id', '!=', $employee->id)->pluck('user_id');
        foreach (User::query()->whereIn('id', $members)->where('is_active', true)->get() as $approver) {
            if (! $this->authorizer->allows($approver, 'timeoff.approve', $storeId)) {
                continue;
            }

            $this->notifier->notify(
                $approver->id, TimeoffServiceProvider::SUBMITTED, ':author asked for a :type leave from :start to :end.',
                ['author' => $employee->name, 'type' => __(TimeoffRequest::typeLabel($type), [], $approver->preferredLocale()), 'start' => $start, 'end' => $end],
                route('timeoff.manage', ['highlight' => $request->id], false), $request->id,
            );
        }

        return $request;
    }

    /**
     * Un gérant saisit un congé pour un employé du magasin : approuvé d'emblée, l'employé est prévenu.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function createFor(User $actor, User $employee, int $storeId, string $type, string $start, string $end, ?string $reason): TimeoffRequest
    {
        if (! $this->authorizer->allows($actor, 'timeoff.create', $storeId)) {
            throw new AuthorizationException;
        }

        $isMember = StoreMembership::query()->where('store_id', $storeId)->where('user_id', $employee->id)->where('is_active', true)->exists();

        if (! $isMember || ! $employee->is_active || ! isset($this->access->storesWith($actor, 'timeoff.create')[$storeId])) {
            throw ValidationException::withMessages(['user_id' => __('This employee is not a member of the selected store.')]);
        }

        $request = TimeoffRequest::create([
            'user_id' => $employee->id, 'store_id' => $storeId, 'type' => $type, 'status' => TimeoffRequest::APPROVED,
            'start_date' => $start, 'end_date' => $end, 'reason' => $this->clean($reason),
            'processed_by' => $actor->id, 'processed_at' => Carbon::now(),
        ]);

        $this->activity->record('timeoff.created_by_manager', $request, $storeId, $this->summary($request) + ['employee' => $employee->name], $actor);
        $this->tell($employee, $actor, $request, TimeoffServiceProvider::APPROVED, 'A :type leave from :start to :end was added to your schedule.');

        return $request;
    }

    /**
     * Approuve ou refuse une demande en attente ; l'employé est prévenu.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function decide(User $actor, TimeoffRequest $request, bool $approve, ?string $note = null): TimeoffRequest
    {
        if (! $this->access->canApprove($actor, $request)) {
            throw new AuthorizationException;
        }

        // Seule une demande en attente se décide : une demande annulée ne ressuscite pas, une décision ne se double pas.
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => __('This request has already been handled.')]);
        }

        $request->status = $approve ? TimeoffRequest::APPROVED : TimeoffRequest::REFUSED;
        $request->admin_note = $this->clean($note);
        $request->processed_by = $actor->id;
        $request->processed_at = Carbon::now();
        $request->save();

        $this->activity->record($approve ? 'timeoff.approved' : 'timeoff.refused', $request, $request->store_id, $this->summary($request), $actor);

        $employee = $request->user;
        $this->tell(
            $employee, $actor, $request,
            $approve ? TimeoffServiceProvider::APPROVED : TimeoffServiceProvider::REFUSED,
            $approve ? 'Your :type leave from :start to :end was approved.' : 'Your :type leave from :start to :end was refused.',
        );

        return $request;
    }

    /**
     * L'employé annule sa demande tant qu'elle est en attente.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancel(User $actor, TimeoffRequest $request): TimeoffRequest
    {
        if ($request->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => __('This request can no longer be cancelled.')]);
        }

        $request->status = TimeoffRequest::CANCELLED;
        $request->save();
        $this->activity->record('timeoff.cancelled', $request, $request->store_id, $this->summary($request), $actor);

        return $request;
    }

    /**
     * Corrige type, dates, motif ou note d'une demande (jamais l'employé, le magasin ni le statut).
     *
     * @param  array{type?: string, start_date?: string, end_date?: string, reason?: string|null, admin_note?: string|null}  $data
     *
     * @throws AuthorizationException
     */
    public function update(User $actor, TimeoffRequest $request, array $data): TimeoffRequest
    {
        if (! $this->access->canUpdate($actor, $request)) {
            throw new AuthorizationException;
        }

        foreach (['reason', 'admin_note'] as $text) {
            if (array_key_exists($text, $data)) {
                $data[$text] = $this->clean($data[$text]);
            }
        }

        $request->fill($data);
        $changed = array_keys($request->getDirty());
        $request->save();

        if ($changed !== []) {
            $this->activity->record('timeoff.updated', $request, $request->store_id, ['fields' => $changed], $actor);
        }

        return $request;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, TimeoffRequest $request): void
    {
        if (! $this->access->canDelete($actor, $request)) {
            throw new AuthorizationException;
        }

        $request->delete();
        $this->activity->record('timeoff.deleted', $request, $request->store_id, $this->summary($request) + ['was' => $request->status], $actor);
    }

    /**
     * @return array<string, string>
     */
    private function summary(TimeoffRequest $request): array
    {
        return ['type' => $request->type, 'start' => $request->start_date->toDateString(), 'end' => $request->end_date->toDateString()];
    }

    private function tell(User $employee, User $actor, TimeoffRequest $request, string $type, string $body): void
    {
        if ($employee->id === $actor->id) {
            return;
        }

        $this->notifier->notify(
            $employee->id, $type, $body,
            [
                // Le type est traduit dans la langue du destinataire.
                'type' => __(TimeoffRequest::typeLabel($request->type), [], $employee->preferredLocale()),
                'start' => $request->start_date->toDateString(), 'end' => $request->end_date->toDateString(),
            ],
            route('timeoff.index', ['highlight' => $request->id], false), $request->id,
        );
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
