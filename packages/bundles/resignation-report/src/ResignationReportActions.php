<?php

namespace Kintai\Bundles\ResignationReport;

use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
        private readonly ResignationDeactivation $deactivation,
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
        $this->assertCanManage($actor, $storeId, $userId);

        $report = new ResignationReport($this->content($data));
        $report->store_id = $storeId;
        $report->user_id = $userId;
        $report->created_by = $actor->id;

        DB::transaction(function () use ($report, $actor): void {
            $report->save();
            $this->syncAccount($report, $actor);
        });

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

        $previous = $report->user_id;

        if ($userId !== $previous) {
            $this->assertEmployee($report->store_id, $userId);
        }

        // Le rapport gouverne le compte lié : il faut pouvoir modifier cet employé (et l'ancien, si le lien change).
        $this->assertCanManage($actor, $report->store_id, $userId);
        $this->assertCanManage($actor, $report->store_id, $previous);

        $report->fill($this->content($data));
        $report->user_id = $userId;
        $changed = array_keys($report->getDirty());

        DB::transaction(function () use ($report, $actor, $previous): void {
            // Un autre employé est lié : l'ancien compte, désactivé par ce rapport, n'en dépend plus.
            if ($previous !== null && $previous !== $report->user_id && $report->deactivated_at !== null) {
                $this->deactivation->reactivate($report, $previous, $actor);
                $report->deactivated_at = null;
            }

            $report->save();
            $this->syncAccount($report, $actor);
        });

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

        // Supprimer un rapport appliqué rend son compte : cela demande aussi le droit sur l'employé.
        if ($report->deactivated_at !== null) {
            if ($report->user_id !== null && ! $this->access->canManageEmployee($actor, $report->store_id, $report->user_id)) {
                throw new AuthorizationException;
            }

            $this->deactivation->reactivate($report, $report->user_id, $actor);
        }

        $report->delete();
        $this->activity->recordFor('resignation_report.deleted', ResignationReport::class, $report->id, $report->store_id, ['employee_id' => $report->user_id], $actor);
    }

    /**
     * Action « Réactiver le compte » : le compte du rapport redevient actif et le restera (le rapport est appliqué une
     * seule fois). Droit de modification du rapport et de l'employé.
     *
     * @throws AuthorizationException
     */
    public function reactivateAccount(User $actor, ResignationReport $report): void
    {
        if (! $this->access->canUpdate($actor, $report)) {
            throw new AuthorizationException;
        }

        $this->assertCanManage($actor, $report->store_id, $report->user_id);

        DB::transaction(function () use ($actor, $report): void {
            $this->deactivation->reactivate($report, $report->user_id, $actor);
            $this->deactivation->markApplied($report);
        });
    }

    /**
     * Désactive ou réactive le compte selon la date ; un refus du Core devient une erreur de validation et annule la
     * sauvegarde.
     *
     * @throws ValidationException
     */
    private function syncAccount(ResignationReport $report, User $actor): void
    {
        try {
            $this->deactivation->sync($report, $actor);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['userId' => $e->getMessage()]);
        }
    }

    /**
     * Lier un rapport à un compte revient à pouvoir le désactiver : il faut pouvoir modifier cet employé dans le magasin.
     *
     * @throws AuthorizationException
     */
    private function assertCanManage(User $actor, int $storeId, ?int $userId): void
    {
        if ($userId !== null && ! $this->access->canManageEmployee($actor, $storeId, $userId)) {
            throw new AuthorizationException;
        }
    }

    /**
     * L'employé lié doit appartenir au magasin.
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
