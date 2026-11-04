<?php

namespace App\Listeners;

use App\Activity\ActivityLogger;
use App\Events\EmployeeCreated;
use App\Events\EmployeePasswordReset;
use App\Events\EmployeeRatesUpdated;
use App\Events\EmployeeUpdated;
use App\Events\PasswordChanged;
use App\Events\ProfileUpdated;
use App\Events\RoleDeleted;
use App\Events\RoleHoldersChanged;
use App\Events\RoleSaved;
use App\Events\ShiftCreated;
use App\Events\ShiftDeleted;
use App\Events\ShiftReplaced;
use App\Events\ShiftRestored;
use App\Events\ShiftTypeCreated;
use App\Events\ShiftTypeDeleted;
use App\Events\ShiftTypeUpdated;
use App\Events\ShiftUpdated;
use App\Events\StoreCreated;
use App\Events\StoreDeleted;
use App\Events\StoreMemberAdded;
use App\Events\StoreMemberRemoved;
use App\Events\StoreMemberUpdated;
use App\Events\StoreUpdated;
use App\Events\UserAnonymized;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Throwable;

/**
 * Journal d'activité : une entrée par événement du Core et par événement d'authentification de Laravel, quelle que
 * soit l'origine de l'écriture (écrans, API, bundles). Les propriétés portent des noms et des valeurs lisibles, jamais
 * de mot de passe ni de jeton. Un échec ne remonte jamais à l'action journalisée.
 */
class RecordActivity
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function login(Login $event): void
    {
        $this->safely(function () use ($event): void {
            $user = $event->user instanceof User ? $event->user : null;
            $this->logger->record('auth.login', $user, null, ['name' => $user?->name], $user);
        });
    }

    public function logout(Logout $event): void
    {
        $this->safely(function () use ($event): void {
            $user = $event->user instanceof User ? $event->user : null;
            $this->logger->record('auth.logout', $user, null, ['name' => $user?->name], $user);
        });
    }

    public function loginFailed(Failed $event): void
    {
        $this->safely(function () use ($event): void {
            $identifier = $event->credentials['login'] ?? $event->credentials['email'] ?? $event->credentials['employee_code'] ?? null;

            // Seul l'identifiant saisi est gardé, jamais le mot de passe.
            $this->logger->record('auth.login_failed', null, null, [
                'login' => is_string($identifier) ? mb_substr($identifier, 0, 255) : null,
            ]);
        });
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->safely(function () use ($event): void {
            $user = $event->user instanceof User ? $event->user : null;
            $this->logger->record('user.password_reset', $user, null, ['name' => $user?->name], $user);
        });
    }

    public function passwordChanged(PasswordChanged $event): void
    {
        $this->forUser('user.password_changed', $event->userId);
    }

    public function profileUpdated(ProfileUpdated $event): void
    {
        $this->forUser('user.profile_updated', $event->userId);
    }

    public function employeeCreated(EmployeeCreated $event): void
    {
        $this->forUser('employee.created', $event->userId, ['source' => $event->source]);
    }

    public function employeeUpdated(EmployeeUpdated $event): void
    {
        $this->forUser('employee.updated', $event->userId);
    }

    public function employeePasswordReset(EmployeePasswordReset $event): void
    {
        $this->forUser('employee.password_reset', $event->userId);
    }

    public function employeeRatesUpdated(EmployeeRatesUpdated $event): void
    {
        $this->forUser('employee.rates_updated', $event->userId);
    }

    public function userAnonymized(UserAnonymized $event): void
    {
        $this->safely(function () use ($event): void {
            // Le compte est déjà anonymisé : son ancien nom n'existe plus, seul l'identifiant reste.
            $this->logger->recordFor('employee.anonymized', (new User)->getMorphClass(), $event->userId);
        });
    }

    public function storeCreated(StoreCreated $event): void
    {
        $this->forStore('store.created', $event->storeId);
    }

    public function storeUpdated(StoreUpdated $event): void
    {
        $this->forStore('store.updated', $event->storeId);
    }

    public function storeDeleted(StoreDeleted $event): void
    {
        $this->forStore('store.deleted', $event->storeId);
    }

    public function storeMemberAdded(StoreMemberAdded $event): void
    {
        $this->forMember('store.member_added', $event->storeId, $event->userId);
    }

    public function storeMemberUpdated(StoreMemberUpdated $event): void
    {
        $this->forMember('store.member_updated', $event->storeId, $event->userId);
    }

    public function storeMemberRemoved(StoreMemberRemoved $event): void
    {
        $this->forMember('store.member_removed', $event->storeId, $event->userId);
    }

    public function shiftTypeCreated(ShiftTypeCreated $event): void
    {
        $this->forShiftType('shift_type.created', $event->shiftTypeId);
    }

    public function shiftTypeUpdated(ShiftTypeUpdated $event): void
    {
        $this->forShiftType('shift_type.updated', $event->shiftTypeId);
    }

    public function shiftTypeDeleted(ShiftTypeDeleted $event): void
    {
        $this->safely(function () use ($event): void {
            $this->logger->recordFor('shift_type.deleted', (new ShiftType)->getMorphClass(), $event->shiftTypeId, null, [
                'name' => $event->name,
                'code' => $event->code,
            ]);
        });
    }

    public function shiftCreated(ShiftCreated $event): void
    {
        $this->forShift('shift.created', $event->shiftId, [], $event->actorId);
    }

    public function shiftDeleted(ShiftDeleted $event): void
    {
        $this->forShift('shift.deleted', $event->shiftId, [], $event->actorId);
    }

    public function shiftRestored(ShiftRestored $event): void
    {
        $this->forShift('shift.restored', $event->shiftId, [], $event->actorId);
    }

    public function shiftUpdated(ShiftUpdated $event): void
    {
        $extra = [];

        if ($event->userChanged) {
            $extra['previous_employee'] = $this->userName($event->previousUserId);
        }

        if ($event->previousSlot !== null && $event->rescheduled) {
            $extra['previous_date'] = $event->previousSlot['date'];
            $extra['previous_start'] = $event->previousSlot['start'];
            $extra['previous_end'] = $event->previousSlot['end'];
            $extra['previous_store'] = Store::withTrashed()->whereKey($event->previousSlot['store_id'])->value('name');
        }

        $this->forShift('shift.updated', $event->shiftId, $extra, $event->actorId);
    }

    public function shiftReplaced(ShiftReplaced $event): void
    {
        $this->safely(function () use ($event): void {
            $old = Shift::withTrashed()->find($event->replacedShiftId);
            $new = Shift::withTrashed()->find($event->replacementShiftId);

            $extra = ['replaced_shift_id' => $event->replacedShiftId];

            if ($old !== null) {
                $extra['previous_employee'] = $this->userName($old->user_id);
                $extra['previous_date'] = $old->shift_date->toDateString();
                $extra['previous_start'] = $old->localStartsAt->format('H:i');
                $extra['previous_end'] = $old->localEndsAt->format('H:i');
            }

            if ($new === null) {
                $this->logger->recordFor('shift.replaced', (new Shift)->getMorphClass(), $event->replacementShiftId, $old?->store_id, $extra, $this->actor($event->actorId));

                return;
            }

            $this->logger->record('shift.replaced', $new, $new->store_id, $this->shiftProperties($new) + $extra, $this->actor($event->actorId));
        });
    }

    public function roleSaved(RoleSaved $event): void
    {
        $this->safely(function () use ($event): void {
            $role = Role::query()->find($event->roleId);

            $this->logger->record($event->created ? 'role.created' : 'role.updated', $role, null, ['name' => $role?->name]);
        });
    }

    public function roleDeleted(RoleDeleted $event): void
    {
        $this->safely(function () use ($event): void {
            $this->logger->recordFor('role.deleted', (new Role)->getMorphClass(), $event->roleId, null, ['name' => $event->name]);
        });
    }

    public function roleHoldersChanged(RoleHoldersChanged $event): void
    {
        $this->safely(function () use ($event): void {
            $role = Role::query()->find($event->roleId);

            $this->logger->record('role.holders_changed', $role, $event->storeId, [
                'name' => $role?->name,
                'change' => $event->added ? 'added' : 'removed',
                'employee' => $this->userName($event->userId),
                'store' => $event->storeId === null ? null : Store::withTrashed()->whereKey($event->storeId)->value('name'),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function forUser(string $action, int $userId, array $extra = []): void
    {
        $this->safely(function () use ($action, $userId, $extra): void {
            $user = User::withTrashed()->find($userId);

            $this->logger->record($action, $user, null, ['name' => $user?->name] + $extra);
        });
    }

    private function forStore(string $action, int $storeId): void
    {
        $this->safely(function () use ($action, $storeId): void {
            $store = Store::withTrashed()->find($storeId);

            $this->logger->record($action, $store, $storeId, ['name' => $store?->name, 'code' => $store?->code]);
        });
    }

    private function forMember(string $action, int $storeId, int $userId): void
    {
        $this->safely(function () use ($action, $storeId, $userId): void {
            $user = User::withTrashed()->find($userId);

            $this->logger->record($action, $user, $storeId, [
                'name' => $user?->name,
                'store' => Store::withTrashed()->whereKey($storeId)->value('name'),
            ]);
        });
    }

    private function forShiftType(string $action, int $shiftTypeId): void
    {
        $this->safely(function () use ($action, $shiftTypeId): void {
            $type = ShiftType::query()->find($shiftTypeId);

            $this->logger->record($action, $type, null, ['name' => $type?->name, 'code' => $type?->code]);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function forShift(string $action, int $shiftId, array $extra, ?int $actorId): void
    {
        $this->safely(function () use ($action, $shiftId, $extra, $actorId): void {
            // Un shift supprimé reste lisible : il est dans la corbeille.
            $shift = Shift::withTrashed()->find($shiftId);

            if ($shift === null) {
                $this->logger->recordFor($action, (new Shift)->getMorphClass(), $shiftId, null, $extra, $this->actor($actorId));

                return;
            }

            $this->logger->record($action, $shift, $shift->store_id, $this->shiftProperties($shift) + $extra, $this->actor($actorId));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftProperties(Shift $shift): array
    {
        return [
            'employee' => $this->userName($shift->user_id),
            'open_shift' => $shift->user_id === null ?: null,
            'store' => Store::withTrashed()->whereKey($shift->store_id)->value('name'),
            'shift_type' => $shift->shift_type_id === null ? null : ShiftType::query()->whereKey($shift->shift_type_id)->value('code'),
            'date' => $shift->shift_date->toDateString(),
            'start' => $shift->localStartsAt->format('H:i'),
            'end' => $shift->localEndsAt->format('H:i'),
        ];
    }

    private function actor(?int $actorId): ?User
    {
        return $actorId === null ? null : User::withTrashed()->find($actorId);
    }

    private function userName(?int $userId): ?string
    {
        return $userId === null ? null : User::withTrashed()->whereKey($userId)->value('name');
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function safely(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
