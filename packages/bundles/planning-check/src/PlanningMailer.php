<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Throwable;

/**
 * Envoi du PDF du planning aux destinataires du magasin : un e-mail par personne (les adresses ne se voient pas), dans la
 * langue de son compte. Les destinataires sont relus à l'envoi : un compte désactivé, sans adresse ou sorti du magasin ne
 * reçoit plus rien.
 */
class PlanningMailer
{
    /**
     * @return list<User>
     */
    public function recipients(int $storeId): array
    {
        $ids = PlanningCheckSetting::query()->firstOrNew(['store_id' => $storeId], PlanningCheckSetting::DEFAULTS)->recipientIds();
        if ($ids === []) {
            return [];
        }

        $members = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->whereIn('user_id', $ids)->pluck('user_id');

        return User::query()->whereIn('id', $members)->where('is_active', true)->whereNotNull('email')->orderBy('name')->get()->all();
    }

    /**
     * @param  array{validated_by?: string, updated?: bool, generated_at?: string}  $context
     * @return array{ok: bool, error: string|null, count: int}
     */
    public function send(Store $store, string $date, ?string $pdf, array $context = []): array
    {
        $recipients = $this->recipients($store->id);
        if ($recipients === []) {
            return ['ok' => false, 'error' => 'no_recipients', 'count' => 0];
        }

        if ($pdf === null) {
            return ['ok' => false, 'error' => 'pdf_failed', 'count' => 0];
        }

        $sent = 0;
        $error = null;
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send((new PlanningMail($store, $date, $pdf, $context))->locale($recipient->preferredLocale()));
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $error = 'mail_failed';
            }
        }

        return ['ok' => $sent > 0, 'error' => $sent > 0 ? null : $error, 'count' => $sent];
    }

    /**
     * Envoie le PDF à une seule personne (le PDF d'essai des réglages).
     */
    public function sendTo(User $user, Store $store, string $date, string $pdf): bool
    {
        try {
            Mail::to($user)->send((new PlanningMail($store, $date, $pdf, []))->locale($user->preferredLocale()));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
