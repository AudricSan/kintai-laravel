<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSend;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Notifications\Notifier;

/**
 * Le cycle de la validation du planning de demain, sur le modèle du rapport journalier : le gérant qui a vérifié les shifts
 * du lendemain les SOUMET ; un détenteur de `planning_check.approve` les VALIDE, et c'est à la validation que le PDF est
 * généré et envoyé aux destinataires. Il peut aussi REFUSER avec un motif (retour au soumetteur). Sans soumission à l'heure
 * limite, les valideurs et les Owners sont prévenus (ESCALADE), sans aucun envoi automatique.
 *
 * Communes aux écrans, à l'API et à la tâche planifiée. Un refus lève `AuthorizationException`, un état ou une saisie qui
 * ne conviennent pas une `ValidationException` (clé `check`, `reason`...). Le jour visé est toujours le lendemain, jamais
 * choisi par l'appelant. Le journal ne garde jamais le motif d'un refus.
 */
class PlanningCheckActions
{
    public function __construct(
        private readonly PlanningCheckAccess $access,
        private readonly DaySchedule $schedule,
        private readonly PlanningPdf $pdf,
        private readonly PlanningMailer $mailer,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Le gérant soumet : « le planning de demain est correct ». Un seul gérant par magasin et par jour suffit ; sans aucun
     * shift, il faut confirmer explicitement « aucun shift demain ».
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function submit(User $actor, int $storeId, bool $confirmEmpty = false, ?Carbon $now = null): PlanningCheck
    {
        $store = $this->enabledStore($actor, 'planning_check.submit', $storeId);
        $date = PlanningCheckPolicy::targetDate($now);
        $check = PlanningCheck::query()->firstOrCreate(['store_id' => $storeId, 'target_date' => $date], ['status' => PlanningCheck::PENDING]);

        if (! $check->isOpen()) {
            throw ValidationException::withMessages(['check' => __('This schedule has already been submitted.')]);
        }

        $shifts = $this->schedule->shifts($storeId, $date);
        if ($shifts->isEmpty() && ! $confirmEmpty) {
            throw ValidationException::withMessages(['check' => __('No shift tomorrow: tick the confirmation to submit anyway.')]);
        }

        $check->forceFill([
            'status' => PlanningCheck::SUBMITTED, 'confirmed_empty' => $shifts->isEmpty(), 'submitted_by' => $actor->id,
            'submitted_at' => Carbon::now(), 'rejected_by' => null, 'rejected_at' => null, 'reject_reason' => null,
            'shifts_hash' => PlanningCheckPolicy::shiftsHash($shifts),
        ])->save();

        $this->activity->record('planning_check.submitted', $check, $storeId, ['date' => $date, 'shifts' => $shifts->count()], $actor);
        $this->notify($this->access->approvers($storeId), $actor, PlanningCheckServiceProvider::SUBMITTED, 'The schedule of :date (:store) is submitted and waiting for your validation.', $check, $store);

        return $check;
    }

    /**
     * Le valideur approuve : le PDF est généré et envoyé. Sans destinataire valide, rien n'est validé ; un échec d'envoi
     * laisse la validation faite mais marque l'envoi « failed » (renvoi possible depuis l'historique).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approve(User $actor, PlanningCheck $check): PlanningCheck
    {
        $store = $this->enabledStore($actor, 'planning_check.approve', $check->store_id);

        if ($check->status !== PlanningCheck::SUBMITTED) {
            throw ValidationException::withMessages(['check' => __('This validation is no longer in the expected state.')]);
        }

        if ($this->mailer->recipients($check->store_id) === []) {
            throw ValidationException::withMessages(['check' => __('No valid recipient: tick employees in the settings.')]);
        }

        $check->forceFill(['status' => PlanningCheck::VALIDATED, 'validated_by' => $actor->id, 'validated_at' => Carbon::now()])->save();
        $this->activity->record('planning_check.validated', $check, $check->store_id, ['date' => $check->target_date->toDateString()], $actor);

        // Un planning rouvert puis revalidé après un premier envoi part comme version mise à jour.
        $sent = $this->deliver($check, $store, $actor, $check->send_status === 'sent');

        $this->notifyValidated($check, $store, $actor, $sent);

        return $check;
    }

    /**
     * Le valideur refuse : motif obligatoire, retour au soumetteur qui corrige et soumet de nouveau.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reject(User $actor, PlanningCheck $check, string $reason): PlanningCheck
    {
        $store = $this->enabledStore($actor, 'planning_check.approve', $check->store_id);

        if ($check->status !== PlanningCheck::SUBMITTED) {
            throw ValidationException::withMessages(['check' => __('This validation is no longer in the expected state.')]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('The reason for the refusal is required.')]);
        }

        $check->forceFill(['status' => PlanningCheck::REJECTED, 'rejected_by' => $actor->id, 'rejected_at' => Carbon::now(), 'reject_reason' => mb_substr($reason, 0, 500)])->save();
        $this->activity->record('planning_check.rejected', $check, $check->store_id, ['date' => $check->target_date->toDateString()], $actor);

        if ($check->submitted_by !== null) {
            $submitter = User::query()->find($check->submitted_by);
            if ($submitter !== null) {
                $this->notify([$submitter], $actor, PlanningCheckServiceProvider::REJECTED, 'The schedule of :date (:store) was refused: :reason', $check, $store, ['reason' => (string) $check->reject_reason]);
            }
        }

        return $check;
    }

    /**
     * Renvoie le PDF d'un planning déjà validé (version mise à jour, ou nouvel essai après un échec).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resend(User $actor, PlanningCheck $check): PlanningCheck
    {
        $store = $this->enabledStore($actor, 'planning_check.approve', $check->store_id);

        if ($check->status !== PlanningCheck::VALIDATED) {
            throw ValidationException::withMessages(['check' => __('This validation is no longer in the expected state.')]);
        }

        if (! $this->deliver($check, $store, $actor, $check->send_status === 'sent')) {
            throw ValidationException::withMessages(['check' => __('The PDF could not be sent: try again later.')]);
        }

        return $check;
    }

    /**
     * « Envoyer maintenant » : le valideur envoie lui-même le planning de demain, à tout moment (hors fenêtre, jour non
     * ouvré, soumission oubliée). Selon l'état : soumet puis valide, valide ce qui était soumis, ou régénère le PDF d'un
     * planning déjà validé et le renvoie comme version mise à jour.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function sendNow(User $actor, int $storeId, bool $confirmEmpty = false, ?Carbon $now = null): PlanningCheck
    {
        $this->enabledStore($actor, 'planning_check.approve', $storeId);
        $check = PlanningCheck::query()->firstOrCreate(['store_id' => $storeId, 'target_date' => PlanningCheckPolicy::targetDate($now)], ['status' => PlanningCheck::PENDING]);

        if ($check->isOpen()) {
            if (! $this->access->allows($actor, 'planning_check.submit', $storeId)) {
                // Le valideur sans droit de soumission confirme et soumet pour le compte du magasin.
                $this->submitAsApprover($actor, $check, $confirmEmpty);
            } else {
                $check = $this->submit($actor, $storeId, $confirmEmpty, $now);
            }
        }

        return $check->status === PlanningCheck::VALIDATED ? $this->resend($actor, $check) : $this->approve($actor, $check);
    }

    /**
     * « Revérifier le planning de demain » : une vérification soumise ou validée repart en attente pour que le cycle
     * (soumettre, valider, envoyer) soit refait. L'historique des envois est conservé : le prochain e-mail est une version
     * mise à jour.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reopen(User $actor, PlanningCheck $check): PlanningCheck
    {
        $this->enabledStore($actor, 'planning_check.submit', $check->store_id);

        if (! in_array($check->status, [PlanningCheck::SUBMITTED, PlanningCheck::VALIDATED], true)) {
            throw ValidationException::withMessages(['check' => __('This validation is no longer in the expected state.')]);
        }

        $check->forceFill([
            'status' => PlanningCheck::PENDING, 'submitted_by' => null, 'submitted_at' => null, 'validated_by' => null, 'validated_at' => null,
            'rejected_by' => null, 'rejected_at' => null, 'reject_reason' => null,
        ])->save();
        $this->activity->record('planning_check.reopened', $check, $check->store_id, ['date' => $check->target_date->toDateString()], $actor);

        return $check;
    }

    /**
     * Échéance : pour chaque magasin activé dont le jour visé est un jour de travail, une validation encore « pending » ou
     * « rejected » après l'heure limite passe « escalated » et prévient les valideurs et les Owners (une seule fois). Aucun
     * PDF n'est envoyé.
     *
     * @return list<int> identifiants des validations escaladées
     */
    public function escalate(?Carbon $now = null): array
    {
        $date = PlanningCheckPolicy::targetDate($now);
        $escalated = [];
        $enabled = PlanningCheckSetting::query()->where('enabled', true)->pluck('store_id')->map(fn ($id): int => (int) $id)->all();

        foreach ($this->access->activeStores($enabled) as $storeId => $name) {
            $setting = PlanningCheckSetting::query()->firstOrNew(['store_id' => $storeId], PlanningCheckSetting::DEFAULTS);
            if (! PlanningCheckPolicy::isDue($setting, $date, $now) || ! PlanningCheckPolicy::isLate($setting, $now)) {
                continue;
            }

            $check = PlanningCheck::query()->firstOrCreate(['store_id' => $storeId, 'target_date' => $date], ['status' => PlanningCheck::PENDING]);
            if (! in_array($check->status, [PlanningCheck::PENDING, PlanningCheck::REJECTED], true)) {
                continue;
            }

            $check->forceFill(['status' => PlanningCheck::ESCALATED, 'escalated_at' => Carbon::now()])->save();
            $store = Store::query()->find($storeId);
            $this->activity->record('planning_check.escalated', $check, $storeId, ['date' => $date], null);

            if ($store !== null) {
                $this->notify($this->access->approvers($storeId), null, PlanningCheckServiceProvider::OVERDUE, 'The schedule of :date (:store) was not submitted by the deadline.', $check, $store);
            }

            $escalated[] = $check->id;
        }

        return $escalated;
    }

    /**
     * Enregistre les réglages d'un magasin (déjà validés).
     *
     * @param  array{enabled: bool, deadline_time: string, window_start_time: string, weekdays: list<int>, mail_recipient_ids: list<int>, include_open_shifts: bool}  $settings
     *
     * @throws AuthorizationException
     */
    public function saveSettings(User $actor, int $storeId, array $settings): PlanningCheckSetting
    {
        if (! $this->access->allows($actor, 'planning_check.settings', $storeId)) {
            throw new AuthorizationException;
        }

        $setting = PlanningCheckSetting::query()->firstOrNew(['store_id' => $storeId], PlanningCheckSetting::DEFAULTS);
        $before = $setting->exists ? $setting->only(array_keys($settings)) : null;
        $setting->fill($settings + ['store_id' => $storeId])->save();

        $this->activity->record('planning_check.settings_updated', Store::query()->find($storeId), $storeId, ['before' => $before, 'after' => $settings], $actor);

        return $setting;
    }

    /**
     * Envoie à l'utilisateur seul le PDF du planning de demain tel qu'il est maintenant (essai des réglages).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function sendTest(User $actor, int $storeId): void
    {
        if (! $this->access->allows($actor, 'planning_check.settings', $storeId)) {
            throw new AuthorizationException;
        }

        if (blank($actor->email)) {
            throw ValidationException::withMessages(['test' => __('Your account has no valid email address.')]);
        }

        $store = Store::query()->findOrFail($storeId);
        $date = PlanningCheckPolicy::targetDate();
        $day = $this->schedule->forDay($storeId, $date, PlanningCheckSetting::query()->firstOrNew(['store_id' => $storeId], PlanningCheckSetting::DEFAULTS)->include_open_shifts);
        $bytes = $this->pdf->bytes($store, $date, $day, ['generated_at' => PlanningCheckPolicy::local()->format('H:i')]);

        if ($bytes === null || ! $this->mailer->sendTo($actor, $store, $date, $bytes)) {
            throw ValidationException::withMessages(['test' => __('The test email could not be sent.')]);
        }

        $this->activity->record('planning_check.test_sent', $store, $storeId, ['date' => $date], $actor);
    }

    /**
     * La version envoyée n'est plus celle du planning : un renvoi est proposé (ou le dernier envoi a échoué).
     */
    public function needsResend(PlanningCheck $check): bool
    {
        if ($check->status !== PlanningCheck::VALIDATED) {
            return false;
        }

        if ($check->send_status === 'failed') {
            return true;
        }

        return $check->send_status === 'sent'
            && PlanningCheckPolicy::shiftsHash($this->schedule->shifts($check->store_id, $check->target_date->toDateString())) !== $check->shifts_hash;
    }

    /**
     * @throws AuthorizationException
     */
    private function enabledStore(User $actor, string $permission, int $storeId): Store
    {
        $store = Store::query()->find($storeId);

        if ($store === null || ! $this->access->allows($actor, $permission, $storeId)) {
            throw new AuthorizationException;
        }

        if (! PlanningCheckSetting::query()->firstOrNew(['store_id' => $storeId], PlanningCheckSetting::DEFAULTS)->enabled) {
            throw ValidationException::withMessages(['check' => __('The schedule validation is not enabled for this store.')]);
        }

        return $store;
    }

    private function submitAsApprover(User $actor, PlanningCheck $check, bool $confirmEmpty): void
    {
        $shifts = $this->schedule->shifts($check->store_id, $check->target_date->toDateString());
        if ($shifts->isEmpty() && ! $confirmEmpty) {
            throw ValidationException::withMessages(['check' => __('No shift tomorrow: tick the confirmation to submit anyway.')]);
        }

        $check->forceFill([
            'status' => PlanningCheck::SUBMITTED, 'confirmed_empty' => $shifts->isEmpty(), 'submitted_by' => $actor->id,
            'submitted_at' => Carbon::now(), 'rejected_by' => null, 'rejected_at' => null, 'reject_reason' => null,
            'shifts_hash' => PlanningCheckPolicy::shiftsHash($shifts),
        ])->save();
        $this->activity->record('planning_check.submitted', $check, $check->store_id, ['date' => $check->target_date->toDateString(), 'shifts' => $shifts->count()], $actor);
    }

    /**
     * Génère le PDF, l'envoie, enregistre le résultat et l'historique.
     */
    private function deliver(PlanningCheck $check, Store $store, User $actor, bool $updated): bool
    {
        $date = $check->target_date->toDateString();
        $shifts = $this->schedule->shifts($check->store_id, $date);
        $hash = PlanningCheckPolicy::shiftsHash($shifts);
        $context = [
            'validated_by' => (string) ($check->validator->name ?? $actor->name),
            'updated' => $updated,
            'generated_at' => PlanningCheckPolicy::local()->format('H:i'),
        ];
        $day = $this->schedule->forDay($check->store_id, $date, PlanningCheckSetting::query()->firstOrNew(['store_id' => $check->store_id], PlanningCheckSetting::DEFAULTS)->include_open_shifts);

        $result = $this->mailer->send($store, $date, $this->pdf->bytes($store, $date, $day, $context), $context);

        PlanningCheckSend::create([
            'planning_check_id' => $check->id, 'sent_by' => $actor->id, 'status' => $result['ok'] ? 'sent' : 'failed',
            'shifts_hash' => $hash, 'recipient_count' => $result['count'], 'error' => $result['error'],
        ]);
        $check->forceFill([
            'send_status' => $result['ok'] ? 'sent' : 'failed',
            'sent_at' => $result['ok'] ? Carbon::now() : $check->sent_at,
            'last_error' => $result['error'],
            'shifts_hash' => $result['ok'] ? $hash : $check->shifts_hash,
        ])->save();

        $this->activity->record($result['ok'] ? ($updated ? 'planning_check.resent' : 'planning_check.sent') : 'planning_check.send_failed', $check, $check->store_id, ['date' => $date, 'recipients' => $result['count']], $actor);

        return $result['ok'];
    }

    private function notifyValidated(PlanningCheck $check, Store $store, User $actor, bool $sent): void
    {
        if ($check->submitted_by !== null) {
            $submitter = User::query()->find($check->submitted_by);
            if ($submitter !== null) {
                $this->notify([$submitter], $actor, PlanningCheckServiceProvider::VALIDATED, 'The schedule of :date (:store) is validated and sent to the recipients.', $check, $store);
            }
        }

        if (! $sent) {
            $targets = array_values(array_filter(array_unique([$check->submitted_by, $actor->id]), fn ($id): bool => $id !== null));
            $this->notify(User::query()->whereIn('id', $targets)->get()->all(), $actor, PlanningCheckServiceProvider::FAILED, 'The PDF of the schedule of :date (:store) could not be sent: try again from the history.', $check, $store);
        }
    }

    /**
     * Notifie des utilisateurs (l'auteur de l'action exclu).
     *
     * @param  list<User>  $users
     * @param  array<string, scalar|null>  $extra  Paramètres de traduction supplémentaires.
     */
    private function notify(array $users, ?User $except, string $type, string $body, PlanningCheck $check, Store $store, array $extra = []): void
    {
        $ids = [];
        foreach ($users as $user) {
            if ($user->id !== $except?->id) {
                $ids[] = $user->id;
            }
        }

        if ($ids === []) {
            return;
        }

        $this->notifier->notify(
            array_values(array_unique($ids)), $type, $body,
            ['date' => $check->target_date->toDateString(), 'store' => $store->name] + $extra,
            route('planning-check.index', ['store' => $check->store_id, 'highlight' => $check->id], false), $check->id,
        );
    }
}
