<?php

namespace Kintai\Bundles\ResignationReport;

use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * Écritures des rapports de démission : droits (`ResignationReportAccess`) et journal. Les champs arrivent déjà validés. Le
 * journal ne contient que des identifiants et des noms de champs modifiés, jamais une donnée personnelle. Un refus lève
 * `AuthorizationException`, une donnée qui ne convient pas une `ValidationException`.
 */
class ResignationReportActions
{
    public function __construct(
        private readonly ResignationReportAccess $access,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Champs de contenu (voir `ResignationReport::FIELDS`).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(User $actor, int $storeId, ?int $userId, array $data): ResignationReport
    {
        if (! $this->access->canCreate($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $this->assertEmployee($storeId, $userId);

        $report = new ResignationReport($this->content($data));
        $report->store_id = $storeId;
        $report->user_id = $userId;
        $report->created_by = $actor->id;
        $report->save();

        $this->activity->record('resignation_report.created', $report, $storeId, ['employee_id' => $userId], $actor);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(User $actor, ResignationReport $report, ?int $userId, array $data): ResignationReport
    {
        if (! $this->access->canUpdate($actor, $report)) {
            throw new AuthorizationException;
        }

        if ($userId !== $report->user_id) {
            $this->assertEmployee($report->store_id, $userId);
        }

        $report->fill($this->content($data));
        $report->user_id = $userId;
        $changed = array_keys($report->getDirty());
        $report->save();

        if ($changed !== []) {
            $this->activity->record('resignation_report.updated', $report, $report->store_id, ['fields' => $changed], $actor);
        }

        return $report;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, ResignationReport $report): void
    {
        if (! $this->access->canDelete($actor, $report)) {
            throw new AuthorizationException;
        }

        $report->delete();
        $this->activity->recordFor('resignation_report.deleted', ResignationReport::class, $report->id, $report->store_id, ['employee_id' => $report->user_id], $actor);
    }

    /**
     * L'employé lié doit appartenir au magasin (le rapport ne désactive aucun compte).
     *
     * @throws ValidationException
     */
    private function assertEmployee(int $storeId, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        if (! StoreMembership::query()->where('store_id', $storeId)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['userId' => __('The chosen employee is not a member of this store.')]);
        }
    }

    /**
     * Seuls les champs du contenu sont repris ; une valeur vide devient « non renseigné ».
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(array $data): array
    {
        $content = [];

        foreach (ResignationReport::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $content[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $content;
    }
}
