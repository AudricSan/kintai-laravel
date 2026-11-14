<?php

namespace App\Actions\Exports;

use App\Activity\ActivityLogger;
use App\Models\Availability;
use App\Models\NotificationPreference;
use App\Models\RoleAssignment;
use App\Models\Shift;
use App\Models\StoreMembership;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Export des données personnelles (portabilité, RGPD) : identité, profil RH, appartenances, rôles, shifts, disponibilités
 * et préférences de notification de l'utilisateur. Jamais de mot de passe, de secret ou code de récupération 2FA, de jeton
 * (iCal, push, « rester connecté ») ni de clé de passkey.
 */
class ExportPersonalData
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        $profile = $user->profile;

        $data = [
            'generated_at' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'identity' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'furigana_first_name' => $user->furigana_first_name,
                'furigana_last_name' => $user->furigana_last_name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'locale' => $user->locale,
                'color' => $user->color,
                'is_active' => $user->is_active,
                'share_email' => $user->share_email,
                'share_phone' => $user->share_phone,
                'share_mobile_phone' => $user->share_mobile_phone,
                'quiet_hours_start' => $user->quiet_hours_start,
                'quiet_hours_end' => $user->quiet_hours_end,
                'created_at' => $user->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'profile' => $profile === null ? null : [
                'gender' => $profile->gender,
                'birth_date' => $profile->birth_date?->toDateString(),
                'address' => $profile->address,
                'postal_code' => $profile->postal_code,
                'phone' => $profile->phone,
                'mobile_phone' => $profile->mobile_phone,
                'tax_classification' => $profile->tax_classification,
                'education' => $profile->education,
                'guarantor_name' => $profile->guarantor_name,
                'guarantor_phone' => $profile->guarantor_phone,
            ],
            // Jointure sur `stores` sans filtre de suppression logique, comme avant : un magasin supprimé reste listé.
            'memberships' => StoreMembership::query()
                ->join('stores', 'stores.id', '=', 'store_user.store_id')
                ->where('store_user.user_id', $user->id)
                ->orderBy('stores.name')
                ->get(['stores.name as store_name', 'stores.code as store_code', 'store_user.staff_code', 'store_user.hire_date', 'store_user.contract_type', 'store_user.is_active'])
                ->map(fn (StoreMembership $membership): array => [
                    'store' => $membership->getAttribute('store_name'),
                    'store_code' => $membership->getAttribute('store_code'),
                    'staff_code' => $membership->staff_code,
                    'hire_date' => $membership->hire_date?->format('Y-m-d'),
                    'contract_type' => $membership->contract_type,
                    'is_active' => (bool) $membership->is_active,
                ])->all(),
            'roles' => RoleAssignment::query()->with(['role', 'store'])->where('user_id', $user->id)->orderBy('id')->get()->map(fn (RoleAssignment $assignment): array => [
                'role' => $assignment->role->name,
                'store' => $assignment->store->name ?? null,
            ])->all(),
            'shifts' => Shift::query()->with(['store', 'shiftType'])->where('user_id', $user->id)->orderBy('shift_date')->orderBy('starts_at')->get()->map(fn (Shift $shift): array => [
                'date' => $shift->shift_date->toDateString(),
                'start_time' => $shift->localStartsAt->format('H:i'),
                'end_time' => $shift->localEndsAt->format('H:i'),
                'ends_next_day' => $shift->crossesMidnight,
                'break_minutes' => $shift->pause_minutes,
                'net_minutes' => $shift->netMinutes,
                'shift_type' => $shift->shiftType->name ?? null,
                'store' => $shift->store->name,
                'notes' => $shift->notes,
            ])->all(),
            'availabilities' => Availability::query()->with('store')->where('user_id', $user->id)->orderBy('store_id')->orderBy('day_of_week')->get()->map(fn (Availability $availability): array => [
                'store' => $availability->store->name,
                'day_of_week' => $availability->day_of_week,
                'start_time' => $availability->starts_at,
                'end_time' => $availability->ends_at,
                'is_available' => $availability->is_available,
            ])->all(),
            'notification_preferences' => NotificationPreference::query()->where('user_id', $user->id)->orderBy('type')->orderBy('channel')->get()->map(fn (NotificationPreference $preference): array => [
                'type' => $preference->type,
                'channel' => $preference->channel,
                'enabled' => $preference->enabled,
            ])->all(),
        ];

        $this->activity->record('export.personal_data', $user, null, [], $user);

        return $data;
    }
}
