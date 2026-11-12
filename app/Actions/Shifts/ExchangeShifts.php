<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use App\Planning\ShiftIssue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Shifts\ShiftExchanger;

/**
 * Échange atomique de deux shifts entre deux employés (contrat `ShiftExchanger`). Chaque shift est remplacé
 * (`ReplaceShift`) par un nouveau shift de même créneau qui appartient à l'autre employé. Les garde-fous du planning
 * sont relevés sur les deux affectations avant toute écriture, en comptant les deux anciens shifts comme disparus :
 * les avertissements sont renvoyés ensemble, et un congé approuvé bloque.
 *
 * Les deux shifts sont relus sous verrou dans la transaction : un shift supprimé, remplacé ou réaffecté entre-temps
 * est refusé proprement. Le taux horaire et les minutes nettes imposés ne suivent pas le créneau (propres à l'employé).
 */
class ExchangeShifts implements ShiftExchanger
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ShiftPreparer $preparer,
        private readonly ReplaceShift $replace,
    ) {}

    /**
     * Point d'entrée des bundles (contrat `ShiftExchanger`) : travaille sur des identifiants.
     *
     * @return array{0: int, 1: int}
     */
    public function exchange(Authenticatable $actor, int $shiftId, int $otherShiftId, bool $force = false): array
    {
        $user = $actor instanceof User ? $actor : User::query()->findOrFail($actor->getAuthIdentifier());

        [$first, $second] = $this->handle($user, $shiftId, $otherShiftId, $force);

        return [$first->id, $second->id];
    }

    /**
     * @return array{0: Shift, 1: Shift} Les remplaçants de `$shiftId` puis de `$otherShiftId`.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function handle(User $actor, int $shiftId, int $otherShiftId, bool $force = false): array
    {
        if ($shiftId === $otherShiftId) {
            throw ValidationException::withMessages(['shift' => __('The two shifts must be different.')]);
        }

        return DB::transaction(function () use ($actor, $shiftId, $otherShiftId, $force): array {
            $shifts = Shift::query()->withTrashed()->whereIn('id', [$shiftId, $otherShiftId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $first = $shifts->get($shiftId) ?? throw ValidationException::withMessages(['shift' => __('This shift is deleted.')]);
            $second = $shifts->get($otherShiftId) ?? throw ValidationException::withMessages(['shift' => __('This shift is deleted.')]);

            foreach ([$first, $second] as $shift) {
                if (! $this->authorizer->allows($actor, 'shifts.update', $shift->store_id)) {
                    throw new AuthorizationException;
                }

                if ($shift->trashed() || $shift->replaced_by_id !== null) {
                    throw ValidationException::withMessages(['shift' => __('This shift is deleted.')]);
                }

                if ($shift->user_id === null) {
                    throw ValidationException::withMessages(['shift' => __('Both shifts must have an employee.')]);
                }
            }

            if ($first->user_id === $second->user_id) {
                throw ValidationException::withMessages(['shift' => __('The two shifts must belong to different employees.')]);
            }

            $firstInput = $this->input($first, (int) $second->user_id);
            $secondInput = $this->input($second, (int) $first->user_id);

            if (! $force) {
                $this->assertNoWarnings($actor, [$firstInput, $secondInput], [$first->id, $second->id]);
            }

            // Les avertissements sont déjà levés ou confirmés : chaque remplacement ignore l'autre shift qui disparaît aussi.
            $newFirst = $this->replace->handle($actor, $first, $firstInput, true, [$second->id]);
            $newSecond = $this->replace->handle($actor, $second, $secondInput, true, [$first->id]);

            return [$newFirst, $newSecond];
        });
    }

    /**
     * Relève les avertissements des deux affectations (sans rien écrire) et les lève ensemble. Un congé approuvé lève
     * sa propre erreur de validation.
     *
     * @param  list<array<string, mixed>>  $inputs
     * @param  list<int>  $ignoreShiftIds
     *
     * @throws ShiftWarningsException
     */
    private function assertNoWarnings(User $actor, array $inputs, array $ignoreShiftIds): void
    {
        $issues = [];

        foreach ($inputs as $input) {
            try {
                $this->preparer->prepare($actor, $input, null, false, $ignoreShiftIds);
            } catch (ShiftWarningsException $e) {
                array_push($issues, ...$e->issues);
            }
        }

        if ($issues === []) {
            return;
        }

        $unique = [];
        foreach ($issues as $issue) {
            $unique[$issue->message] = $issue;
        }

        /** @var list<ShiftIssue> $list */
        $list = array_values($unique);

        throw new ShiftWarningsException($list);
    }

    /**
     * Saisie du remplaçant : même créneau, autre employé.
     *
     * @return array<string, mixed>
     */
    private function input(Shift $shift, int $userId): array
    {
        return [
            'store_id' => $shift->store_id,
            'user_id' => $userId,
            'shift_date' => $shift->shift_date->toDateString(),
            'start_time' => $shift->localStartsAt->format('H:i'),
            'end_time' => $shift->localEndsAt->format('H:i'),
            'pause_minutes' => $shift->pause_minutes,
            'notes' => $shift->notes,
        ];
    }
}
