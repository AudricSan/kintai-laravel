<?php

namespace Kintai\Bundles\HiringReport;

use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\HiringReport\Models\HiringReport;
use Kintai\Contracts\Events\EmployeeCreated;

/**
 * Crée le rapport d'embauche d'un employé dès sa création (événement `EmployeeCreated`, émis une fois l'employé rattaché
 * à son magasin), à partir de sa fiche : formulaire « Nouvel employé » comme import. Un rapport par magasin de l'employé ;
 * un employé sans magasin n'en a pas, et un magasin qui a déjà son rapport n'en reçoit pas un second.
 */
class HiringReportOnEmployeeCreated
{
    /** Champs de la fiche (`EmployeeProfile`) recopiés tels quels. */
    private const PROFILE_FIELDS = [
        'gender', 'birth_date', 'address', 'postal_code', 'phone', 'mobile_phone', 'tax_classification', 'education',
        'guarantor_name', 'guarantor_phone',
    ];

    public function handle(EmployeeCreated $event): void
    {
        $user = User::query()->with('profile')->find($event->userId);

        if ($user === null) {
            return;
        }

        $storeIds = StoreMembership::query()->where('user_id', $user->id)->pluck('store_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $author = Auth::user();

        foreach ($storeIds as $storeId) {
            if (HiringReport::query()->where('store_id', $storeId)->where('user_id', $user->id)->exists()) {
                continue;
            }

            $report = new HiringReport([
                'employee_number' => $this->blank($user->employee_code),
                'employee_name' => $this->blank($user->name),
                'furigana_last_name' => $this->blank($user->furigana_last_name),
                'furigana_first_name' => $this->blank($user->furigana_first_name),
                'email' => $this->blank($user->email),
                'hire_date' => now((string) config('kintai.timezone', 'UTC'))->toDateString(),
                'hired_by' => $author instanceof User ? $this->blank($author->name) : null,
            ]);

            foreach (self::PROFILE_FIELDS as $field) {
                $value = $user->profile?->{$field};
                $report->{$field} = is_string($value) ? $this->blank($value) : $value;
            }

            $report->store_id = $storeId;
            $report->user_id = $user->id;
            $report->created_by = $author instanceof User ? $author->id : null;
            $report->save();
        }
    }

    private function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
