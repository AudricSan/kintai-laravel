<?php

namespace Kintai\Bundles\SalaryReport;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\Models\SalaryReportDeduction;
use Kintai\Bundles\SalaryReport\Models\SalaryReportMember;
use Kintai\Bundles\SalaryReport\Models\SalaryReportSetting;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * Calcul et écritures des rapports de salaire, communs aux écrans et à l'API : droits (`SalaryReportAccess`) et journal.
 * Les champs arrivent déjà validés. Le journal ne contient jamais un montant ni un nom : seulement des identifiants, le
 * mois et les noms de champs modifiés. Un refus lève `AuthorizationException`, une donnée qui ne convient pas une
 * `ValidationException` (sans valeur chiffrée dans le message).
 */
class SalaryReportActions
{
    /** Plage de calcul la plus longue, en jours. */
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly SalaryReportAccess $access,
        private readonly SalaryCalculator $calculator,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Calcule (sans rien enregistrer) le rapport d'un magasin, ou d'un employé de ce magasin, sur une période.
     *
     * @return array{preset: array<string, mixed>, detail: array<string, mixed>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function calculate(User $actor, int $storeId, string $from, string $to, ?int $employeeId = null): array
    {
        if (! $this->access->canGenerate($actor, $storeId)) {
            throw new AuthorizationException;
        }

        if (! Carbon::hasFormat($from, 'Y-m-d') || ! Carbon::hasFormat($to, 'Y-m-d') || Carbon::parse($from)->toDateString() !== $from || Carbon::parse($to)->toDateString() !== $to || $from > $to) {
            throw ValidationException::withMessages(['from' => __('This period cannot be calculated.')]);
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => __('This period cannot be calculated.')]);
        }

        return $this->calculator->compute($this->store($storeId), $from, $to, $this->employee($storeId, $employeeId));
    }

    /**
     * Crée le rapport d'un mois. Un seul rapport par magasin, par mois et par employé (ou global du magasin).
     *
     * @param  array<string, mixed>  $data  Champs de contenu (voir `content()`).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(User $actor, int $storeId, string $month, ?int $employeeId, array $data): SalaryReport
    {
        if (! $this->access->canGenerate($actor, $storeId)) {
            throw new AuthorizationException;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => __('This period cannot be calculated.')]);
        }

        $employee = $this->employee($storeId, $employeeId);

        $exists = SalaryReport::query()->where('store_id', $storeId)->where('target_month', $month)
            ->when($employee === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $employee?->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['month' => __('A salary report already exists for this month and this employee.')]);
        }

        $report = SalaryReport::create($this->content($storeId, $data) + [
            'store_id' => $storeId, 'user_id' => $employee?->id, 'target_month' => $month, 'created_by' => $actor->id,
        ]);

        $this->activity->record('salary_report.created', $report, $storeId, ['month' => $month, 'employee_id' => $employee?->id], $actor);

        return $report;
    }

    /**
     * Modifie le contenu d'un rapport (jamais le magasin, le mois ni l'employé).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(User $actor, SalaryReport $report, array $data): SalaryReport
    {
        if (! $this->access->canEdit($actor, $report)) {
            throw new AuthorizationException;
        }

        $report->fill($this->content($report->store_id, $data));
        $changed = array_keys($report->getDirty());
        $report->save();

        if ($changed !== []) {
            $this->activity->record('salary_report.updated', $report, $report->store_id, ['month' => $report->target_month, 'fields' => $changed], $actor);
        }

        return $report;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, SalaryReport $report): void
    {
        if (! $this->access->canDelete($actor, $report)) {
            throw new AuthorizationException;
        }

        $month = $report->target_month;
        $report->delete();
        $this->activity->recordFor('salary_report.deleted', SalaryReport::class, $report->id, $report->store_id, ['month' => $month], $actor);
    }

    /**
     * Enregistre les retenues d'un magasin et la liste de ses employés assujettis (ceux qui ne sont pas listés ne le sont
     * plus).
     *
     * @param  array{deductions_enabled: bool, health_insurance_rate: numeric, pension_rate: numeric, employment_insurance_rate: numeric, income_tax_rate: numeric, resident_tax_monthly: numeric}  $settings
     * @param  list<int>  $subjectIds  Employés du magasin assujettis aux retenues.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saveSettings(User $actor, int $storeId, array $settings, array $subjectIds): SalaryReportSetting
    {
        if (! $this->access->canManageSettings($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $members = StoreMembership::query()->where('store_id', $storeId)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
        if (array_diff($subjectIds, $members) !== []) {
            throw ValidationException::withMessages(['subjects' => __('A chosen employee is not a member of this store.')]);
        }

        $setting = DB::transaction(function () use ($storeId, $settings, $members, $subjectIds): SalaryReportSetting {
            $setting = SalaryReportSetting::forStore($storeId);
            $setting->fill($settings + ['store_id' => $storeId])->save();

            foreach ($members as $userId) {
                SalaryReportMember::query()->updateOrCreate(
                    ['store_id' => $storeId, 'user_id' => $userId],
                    ['subject_to_deductions' => in_array($userId, $subjectIds, true)],
                );
            }

            return $setting;
        });

        $this->activity->record('salary_report.settings_updated', Store::query()->find($storeId), $storeId, ['subject_count' => count($subjectIds)], $actor);

        return $setting;
    }

    /**
     * Crée (`$deductionId` nul) ou modifie une retenue personnalisée du magasin : nom libre unique dans le magasin, type
     * `percent` (pourcentage du brut, 100 au plus) ou `fixed` (montant mensuel par employé payé), valeur positive ou nulle,
     * 20 retenues au plus. Le journal ne reçoit ni le nom ni la valeur.
     *
     * @param  array{name: string, kind: string, value: int|float|string, is_active?: bool}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saveDeduction(User $actor, int $storeId, ?int $deductionId, array $data): SalaryReportDeduction
    {
        if (! $this->access->canManageSettings($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $deduction = $deductionId === null ? null : SalaryReportDeduction::query()->where('store_id', $storeId)->find($deductionId);
        if ($deductionId !== null && $deduction === null) {
            throw new AuthorizationException;
        }

        $name = trim($data['name']);
        $kind = $data['kind'];
        $value = $data['value'];
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('The name of a deduction is required (100 characters at most).')]);
        }
        if (! in_array($kind, SalaryReportDeduction::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('The type of this deduction is not valid.')]);
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > ($kind === SalaryReportDeduction::PERCENT ? 100 : 9999999)) {
            throw ValidationException::withMessages(['value' => __('The value of this deduction is not valid.')]);
        }

        $deduction = DB::transaction(function () use ($storeId, $deduction, $name, $kind, $value, $data): SalaryReportDeduction {
            $others = SalaryReportDeduction::query()->where('store_id', $storeId)->when($deduction !== null, fn ($q) => $q->where('id', '!=', $deduction?->id));

            if ((clone $others)->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => __('A deduction with this name already exists in this store.')]);
            }
            if ($deduction === null && $others->count() >= SalaryReportDeduction::MAX_PER_STORE) {
                throw ValidationException::withMessages(['name' => __('A store cannot have more than :max custom deductions.', ['max' => SalaryReportDeduction::MAX_PER_STORE])]);
            }

            $deduction ??= new SalaryReportDeduction(['store_id' => $storeId, 'sort_order' => (int) SalaryReportDeduction::query()->where('store_id', $storeId)->max('sort_order') + 1]);
            $deduction->fill(['name' => $name, 'kind' => $kind, 'value' => $value] + (array_key_exists('is_active', $data) ? ['is_active' => $data['is_active']] : []))->save();

            return $deduction;
        });

        $this->activity->record('salary_report.deduction_saved', Store::query()->find($storeId), $storeId, ['deduction_id' => $deduction->id], $actor);

        return $deduction;
    }

    /**
     * Supprime pour de bon une retenue personnalisée ; les rapports déjà enregistrés gardent leur instantané.
     *
     * @throws AuthorizationException
     */
    public function deleteDeduction(User $actor, int $storeId, int $deductionId): void
    {
        if (! $this->access->canManageSettings($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $deduction = SalaryReportDeduction::query()->where('store_id', $storeId)->find($deductionId) ?? throw new AuthorizationException;
        $deduction->delete();

        $this->activity->record('salary_report.deduction_deleted', Store::query()->find($storeId), $storeId, ['deduction_id' => $deductionId], $actor);
    }

    private function store(int $storeId): Store
    {
        $store = Store::query()->find($storeId);

        if ($store === null) {
            throw new AuthorizationException;
        }

        return $store;
    }

    /**
     * L'employé d'un rapport : membre du magasin, sinon une erreur (sans dire s'il existe ailleurs).
     */
    private function employee(int $storeId, ?int $employeeId): ?User
    {
        if ($employeeId === null) {
            return null;
        }

        $employee = StoreMembership::query()->where('store_id', $storeId)->where('user_id', $employeeId)->exists()
            ? User::query()->find($employeeId)
            : null;

        if ($employee === null) {
            throw ValidationException::withMessages(['employee' => __('The chosen employee is not a member of this store.')]);
        }

        return $employee;
    }

    /**
     * Champs de contenu retenus : montants, heures, effectifs, textes, responsable (parmi ceux du magasin) et heures par
     * employé (réduites aux membres du magasin).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function content(int $storeId, array $data): array
    {
        $content = [];

        foreach ([...SalaryReport::AMOUNTS, ...SalaryReport::HOURS] as $field) {
            if (array_key_exists($field, $data)) {
                $content[$field] = $data[$field] ?? 0;
            }
        }

        foreach (SalaryReport::COUNTS as $field) {
            if (array_key_exists($field, $data)) {
                $content[$field] = (int) ($data[$field] ?? 0);
            }
        }

        foreach (['hire_registrations', 'remarks'] as $field) {
            if (array_key_exists($field, $data)) {
                $text = trim((string) $data[$field]);
                $content[$field] = $text === '' ? null : $text;
            }
        }

        if (array_key_exists('person_in_charge_id', $data)) {
            $id = $data['person_in_charge_id'] === null || $data['person_in_charge_id'] === '' ? null : (int) $data['person_in_charge_id'];
            if ($id !== null && ! isset($this->access->managers($storeId)[$id])) {
                throw ValidationException::withMessages(['person_in_charge_id' => __('The chosen person in charge cannot generate salary reports for this store.')]);
            }
            $content['person_in_charge_id'] = $id;
        }

        // Instantané des retenues personnalisées appliquées au calcul (nom libre, type, valeur, montant).
        if (array_key_exists('deduction_lines', $data) && is_array($data['deduction_lines'])) {
            $lines = [];
            foreach ($data['deduction_lines'] as $row) {
                if (is_array($row) && isset($row['name'], $row['kind'], $row['value'], $row['amount']) && is_string($row['name']) && in_array($row['kind'], SalaryReportDeduction::KINDS, true) && is_numeric($row['value']) && is_numeric($row['amount'])) {
                    $lines[] = ['name' => mb_substr($row['name'], 0, 100), 'kind' => $row['kind'], 'value' => round((float) $row['value'], 2), 'amount' => round(max(0.0, (float) $row['amount']), 2)];
                }
            }
            $content['deduction_lines'] = $lines === [] ? null : $lines;
        }

        if (array_key_exists('employee_hours', $data) && is_array($data['employee_hours'])) {
            $members = StoreMembership::query()->where('store_id', $storeId)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $hours = [];
            foreach ($data['employee_hours'] as $row) {
                if (is_array($row) && isset($row['user_id'], $row['minutes']) && in_array((int) $row['user_id'], $members, true)) {
                    $hours[] = ['user_id' => (int) $row['user_id'], 'minutes' => max(0, (int) $row['minutes'])];
                }
            }
            $content['employee_hours'] = $hours;
        }

        return $content;
    }
}
