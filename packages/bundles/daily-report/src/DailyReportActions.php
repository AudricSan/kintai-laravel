<?php

namespace Kintai\Bundles\DailyReport;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;

/**
 * Écritures des rapports, communes aux écrans, à l'API et à la tâche planifiée : droits (`DailyReportAccess`), journal
 * (jamais le contenu des notes) et notifications. Les champs arrivent déjà validés. Un refus lève
 * `AuthorizationException`, un état ou une date qui ne conviennent pas une `ValidationException`.
 */
class DailyReportActions
{
    public function __construct(
        private readonly DailyReportAccess $access,
        private readonly DailyReportMailer $mailer,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Rédige le rapport d'un jour (brouillon). Un seul rapport par magasin et par jour ; hors Owner, pas de date passée.
     *
     * @param  array{report_date: string, sales_total?: numeric|null, customer_count?: int|null, labor_cost?: numeric|null, waste_total?: numeric|null, notes?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(User $actor, int $storeId, array $data): DailyReport
    {
        if (! $this->access->canCreate($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $date = Carbon::parse($data['report_date'])->toDateString();

        if (! $this->access->isOwner($actor) && $date < self::today()) {
            throw ValidationException::withMessages(['report_date' => __('A report cannot be created for a past date.')]);
        }

        if (DailyReport::query()->where('store_id', $storeId)->whereDate('report_date', $date)->exists()) {
            throw ValidationException::withMessages(['report_date' => __('A report already exists for this date.')]);
        }

        $report = DailyReport::create($this->content($data) + [
            'store_id' => $storeId, 'author_id' => $actor->id, 'report_date' => $date, 'status' => DailyReport::DRAFT,
        ]);

        $this->activity->record('daily_report.created', $report, $storeId, ['date' => $date], $actor);

        return $report;
    }

    /**
     * Modifie les chiffres et les notes (jamais la date, le magasin ni l'état).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    public function update(User $actor, DailyReport $report, array $data): DailyReport
    {
        if (! $this->access->canEdit($actor, $report)) {
            throw new AuthorizationException;
        }

        $report->fill($this->content($data));
        $changed = array_keys($report->getDirty());
        $report->save();

        if ($changed !== []) {
            $this->activity->record('daily_report.updated', $report, $report->store_id, ['date' => $report->report_date->toDateString(), 'fields' => $changed], $actor);
        }

        return $report;
    }

    /**
     * Soumet un brouillon ; les valideurs du magasin sont prévenus.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function submit(User $actor, DailyReport $report): DailyReport
    {
        if (! $this->access->allows($actor, 'daily_reports.submit', $report->store_id)) {
            throw new AuthorizationException;
        }

        if ($report->status !== DailyReport::DRAFT) {
            throw ValidationException::withMessages(['report' => __('This report has already been submitted.')]);
        }

        $report->forceFill(['status' => DailyReport::SUBMITTED, 'submitted_at' => Carbon::now()])->save();
        $this->activity->record('daily_report.submitted', $report, $report->store_id, ['date' => $report->report_date->toDateString()], $actor);

        $store = $this->storeName($report);
        foreach ($this->access->holders($report->store_id, 'daily_reports.approve') as $approver) {
            if ($approver->id === $actor->id) {
                continue;
            }

            $this->notifier->notify(
                $approver->id, DailyReportServiceProvider::SUBMITTED, 'A daily report for :date (:store) is waiting for your validation.',
                ['date' => $report->report_date->toDateString(), 'store' => $store], route('daily-report.show', $report->id, false), $report->id,
            );
        }

        return $report;
    }

    /**
     * Valide un rapport soumis ; l'auteur est prévenu, et le rapport part par e-mail si le magasin l'a demandé.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function validate(User $actor, DailyReport $report): DailyReport
    {
        if (! $this->access->allows($actor, 'daily_reports.approve', $report->store_id)) {
            throw new AuthorizationException;
        }

        if ($report->status !== DailyReport::SUBMITTED) {
            throw ValidationException::withMessages(['report' => __('This report is not waiting for validation.')]);
        }

        $this->markValidated($report, $actor);
        $this->activity->record('daily_report.validated', $report, $report->store_id, ['date' => $report->report_date->toDateString()], $actor);

        if ($report->author_id !== null && $report->author_id !== $actor->id) {
            $this->notifier->notify(
                $report->author_id, DailyReportServiceProvider::VALIDATED, 'Your daily report for :date (:store) was validated.',
                ['date' => $report->report_date->toDateString(), 'store' => $this->storeName($report)], route('daily-report.show', $report->id, false), $report->id,
            );
        }

        $this->autoSend($report, $actor);

        return $report;
    }

    /**
     * Validation automatique (tâche planifiée) : rapport du jour pas encore validé, brouillon compris ; personne n'est
     * indiqué comme valideur.
     */
    public function autoValidate(DailyReport $report): DailyReport
    {
        $this->markValidated($report, null);
        $this->activity->record('daily_report.auto_validated', $report, $report->store_id, ['date' => $report->report_date->toDateString()], null);
        $this->autoSend($report, null);

        return $report;
    }

    /**
     * Envoie le rapport validé aux destinataires du magasin.
     *
     * @throws AuthorizationException
     * @throws ValidationException Pas de destinataire, ou aucun e-mail n'a pu partir.
     */
    public function sendMail(User $actor, DailyReport $report): DailyReport
    {
        if (! $this->access->canSendMail($actor, $report)) {
            throw new AuthorizationException;
        }

        if ($this->mailer->recipients($report->store_id) === []) {
            throw ValidationException::withMessages(['report' => __('No recipient selected: choose employees in the store settings.')]);
        }

        if (! $this->deliver($report, $actor)) {
            throw ValidationException::withMessages(['report' => __('The email could not be sent. Check the mail settings.')]);
        }

        return $report;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, DailyReport $report): void
    {
        if (! $this->access->canDelete($actor, $report)) {
            throw new AuthorizationException;
        }

        $date = $report->report_date->toDateString();
        $report->delete();
        $this->activity->recordFor('daily_report.deleted', DailyReport::class, $report->id, $report->store_id, ['date' => $date, 'was' => $report->status], $actor);
    }

    /**
     * Enregistre les réglages d'un magasin (déjà validés).
     *
     * @param  array{auto_send_on_validate: bool, auto_validate_time: string|null, reminder_time: string, no_report_time: string, show_columns: list<string>, cumulative_mode: string, mail_recipient_ids: list<int>}  $settings
     *
     * @throws AuthorizationException
     */
    public function saveSettings(User $actor, int $storeId, array $settings): DailyReportSetting
    {
        if (! $this->access->canManageSettings($actor, $storeId)) {
            throw new AuthorizationException;
        }

        $setting = DailyReportSetting::query()->firstOrNew(['store_id' => $storeId], DailyReportSetting::DEFAULTS);
        $before = $setting->exists ? $setting->only(array_keys($settings)) : null;
        $setting->fill($settings + ['store_id' => $storeId])->save();

        $this->activity->record('daily_report.settings_updated', Store::query()->find($storeId), $storeId, ['before' => $before, 'after' => $settings], $actor);

        return $setting;
    }

    public static function today(): string
    {
        return Carbon::now((string) config('kintai.timezone', 'UTC'))->toDateString();
    }

    private function markValidated(DailyReport $report, ?User $actor): void
    {
        $report->forceFill(['status' => DailyReport::VALIDATED, 'validated_by' => $actor?->id, 'validated_at' => Carbon::now()])->save();
    }

    private function autoSend(DailyReport $report, ?User $actor): void
    {
        $setting = DailyReportSetting::query()->firstOrNew(['store_id' => $report->store_id], DailyReportSetting::DEFAULTS);

        if ($setting->auto_send_on_validate && $setting->recipientIds() !== []) {
            // Un échec d'envoi n'annule pas la validation : le gérant peut renvoyer le rapport.
            $this->deliver($report, $actor);
        }
    }

    /**
     * Envoie le rapport et en garde la trace. En validation automatique, le PDF est dans la langue de l'auteur.
     */
    private function deliver(DailyReport $report, ?User $actor): bool
    {
        $locale = $actor === null ? ($report->author?->preferredLocale()) : null;

        if (! $this->mailer->send($report, $locale)) {
            return false;
        }

        $report->forceFill(['mail_sent_at' => Carbon::now()])->save();
        $this->activity->record('daily_report.mail_sent', $report, $report->store_id, ['date' => $report->report_date->toDateString()], $actor);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(array $data): array
    {
        $content = [];

        foreach (['sales_total', 'labor_cost', 'waste_total'] as $field) {
            if (array_key_exists($field, $data)) {
                $content[$field] = $data[$field] ?? 0;
            }
        }

        if (array_key_exists('customer_count', $data)) {
            $content['customer_count'] = (int) ($data['customer_count'] ?? 0);
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string) $data['notes']);
            $content['notes'] = $notes === '' ? null : $notes;
        }

        return $content;
    }

    private function storeName(DailyReport $report): string
    {
        return (string) ($report->store->name ?? '');
    }
}
