<?php

namespace Tests\Feature\Notifications;

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\DeleteShifts;
use App\Actions\Shifts\MoveShift;
use App\Actions\Shifts\ReplaceShift;
use App\Actions\Shifts\ResolveConflict;
use App\Actions\Shifts\RestoreShift;
use App\Actions\Shifts\UpdateShift;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ShiftNotificationsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $emma;

    private User $colin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha']);
        $this->manager = User::factory()->create();
        $this->manager->assignRole($this->customRole(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete']), $this->store);
        $this->emma = $this->employeeOf($this->store, ['name' => 'Emma', 'email' => 'emma@example.test']);
        $this->colin = $this->employeeOf($this->store, ['name' => 'Colin', 'email' => 'colin@example.test']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'store_id' => $this->store->id,
            'user_id' => $this->emma->id,
            'shift_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'pause_minutes' => 60,
        ];
    }

    private function shift(?User $user = null, string $date = '2026-11-02', string $start = '09:00', string $end = '17:00'): Shift
    {
        return Shift::factory()->create([
            'store_id' => $this->store->id, 'user_id' => $user?->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ])->fresh();
    }

    /**
     * @return list<string>
     */
    private function types(User $user): array
    {
        return $user->notifications()->orderBy('created_at')->pluck('type')->all();
    }

    public function test_creating_an_assigned_shift_notifies_the_employee(): void
    {
        $shift = app(CreateShift::class)->handle($this->manager, $this->input());

        $this->assertSame(['shift_assigned'], $this->types($this->emma));

        $notification = $this->emma->notifications()->firstOrFail();
        $this->assertSame($shift->id, $notification->data['reference_id']);
        $this->assertSame('shift', $notification->data['reference_type']);
        $this->assertSame('/my-shifts/day?date=2026-11-02', $notification->data['link']);
        $this->assertSame(['date' => '2026-11-02', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha'], $notification->data['params']);
        $this->assertSame([], $this->types($this->manager));
    }

    public function test_an_open_shift_notifies_nobody(): void
    {
        app(CreateShift::class)->handle($this->manager, $this->input(['user_id' => null]));

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_reassigning_notifies_both_employees(): void
    {
        $shift = $this->shift($this->emma);

        app(UpdateShift::class)->handle($this->manager, $shift, ['user_id' => $this->colin->id]);

        $this->assertSame(['shift_assigned'], $this->types($this->colin));
        $this->assertSame(['shift_deleted'], $this->types($this->emma));
        $this->assertSame(20261102, $this->emma->notifications()->firstOrFail()->data['reference_id']);
        $this->assertSame('shift_day', $this->emma->notifications()->firstOrFail()->data['reference_type']);
    }

    public function test_the_previous_employee_is_told_about_the_original_slot(): void
    {
        $shift = $this->shift($this->emma);

        app(UpdateShift::class)->handle($this->manager, $shift, ['user_id' => $this->colin->id, 'shift_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '18:00']);

        $this->assertSame(['date' => '2026-11-02', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha'], $this->emma->notifications()->firstOrFail()->data['params']);
        $this->assertSame('2026-11-03', $this->colin->notifications()->firstOrFail()->data['params']['date']);
    }

    public function test_unassigning_notifies_the_previous_employee(): void
    {
        app(UpdateShift::class)->handle($this->manager, $this->shift($this->emma), ['user_id' => null]);

        $this->assertSame(['shift_deleted'], $this->types($this->emma));
    }

    public function test_rescheduling_notifies_the_same_employee(): void
    {
        $shift = $this->shift($this->emma);

        app(UpdateShift::class)->handle($this->manager, $shift, ['start_time' => '10:00', 'end_time' => '18:00']);
        app(MoveShift::class)->handle($this->manager, $shift->refresh(), ['shift_date' => '2026-11-03']);

        $this->assertSame(['shift_updated', 'shift_updated'], $this->types($this->emma));
    }

    public function test_changing_only_notes_or_pause_notifies_nobody(): void
    {
        $shift = $this->shift($this->emma);

        app(UpdateShift::class)->handle($this->manager, $shift, ['notes' => 'Bring the keys', 'pause_minutes' => 30]);

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_deleting_notifies_the_employee_once_per_shift(): void
    {
        $single = $this->shift($this->emma);
        $a = $this->shift($this->emma, '2026-11-03');
        $b = $this->shift($this->colin, '2026-11-03');

        app(DeleteShift::class)->handle($this->manager, $single);
        app(DeleteShifts::class)->handle($this->manager, [$a->id, $b->id]);

        $this->assertSame(['shift_deleted', 'shift_deleted'], $this->types($this->emma));
        $this->assertSame(['shift_deleted'], $this->types($this->colin));
    }

    public function test_deleting_an_open_shift_notifies_nobody(): void
    {
        app(DeleteShift::class)->handle($this->manager, $this->shift());

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_restoring_notifies_the_employee(): void
    {
        $shift = $this->shift($this->emma);
        app(DeleteShift::class)->handle($this->manager, $shift);

        app(RestoreShift::class)->handle($this->manager, $shift->refresh());

        // Les deux notifications partent dans la même seconde : l'ordre n'est pas garanti, seul l'ensemble compte.
        $this->assertEqualsCanonicalizing(['shift_deleted', 'shift_assigned'], $this->types($this->emma));
    }

    public function test_replacing_with_another_slot_for_the_same_employee_sends_a_single_update(): void
    {
        $old = $this->shift($this->emma);

        app(ReplaceShift::class)->handle($this->manager, $old, $this->input(['start_time' => '11:00', 'end_time' => '19:00']));

        $this->assertSame(['shift_updated'], $this->types($this->emma));
        $this->assertSame('11:00', $this->emma->notifications()->firstOrFail()->data['params']['start']);
    }

    public function test_replacing_with_another_employee_notifies_both(): void
    {
        $old = $this->shift($this->emma);

        app(ReplaceShift::class)->handle($this->manager, $old, $this->input(['user_id' => $this->colin->id]));

        $this->assertSame(['shift_assigned'], $this->types($this->colin));
        $this->assertSame(['shift_deleted'], $this->types($this->emma));
    }

    public function test_replacing_with_an_identical_slot_for_the_same_employee_notifies_nobody(): void
    {
        $old = $this->shift($this->emma);

        app(ReplaceShift::class)->handle($this->manager, $old, $this->input());

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_resolving_a_conflict_sends_a_single_update(): void
    {
        $older = $this->shift($this->emma, '2026-11-02', '09:00', '13:00');
        $newer = $this->shift($this->emma, '2026-11-02', '12:00', '17:00');

        app(ResolveConflict::class)->handle($this->manager, $older, $newer);

        $this->assertSame(['shift_updated'], $this->types($this->emma));
    }

    public function test_the_actor_is_never_notified_about_their_own_change(): void
    {
        $self = $this->employeeOf($this->store);
        $self->assignRole($this->customRole(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete']), $this->store);

        $shift = app(CreateShift::class)->handle($self, $this->input(['user_id' => $self->id]));
        app(UpdateShift::class)->handle($self, $shift, ['start_time' => '10:00', 'end_time' => '18:00']);
        app(DeleteShift::class)->handle($self, $shift);

        $this->assertSame(0, $self->notifications()->count());
    }

    public function test_an_inactive_employee_is_not_notified(): void
    {
        $this->emma->forceFill(['is_active' => false])->save();

        app(CreateShift::class)->handle($this->manager, $this->input());

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_the_mail_follows_the_defaults_and_the_preferences(): void
    {
        $shift = $this->shift($this->emma);

        // shift_updated : push seulement par défaut, pas d'e-mail.
        app(UpdateShift::class)->handle($this->manager, $shift, ['start_time' => '10:00', 'end_time' => '18:00']);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());

        // shift_assigned : e-mail par défaut ; désactivé par l'employé : cloche seulement.
        app(CreateShift::class)->handle($this->manager, $this->input(['shift_date' => '2026-11-04']));
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());

        app(NotificationPreferences::class)->set($this->emma, 'shift_assigned', 'mail', false);
        app(CreateShift::class)->handle($this->manager, $this->input(['shift_date' => '2026-11-05']));
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(3, $this->emma->notifications()->count());
    }

    public function test_the_mail_is_written_in_the_recipient_language(): void
    {
        $this->colin->forceFill(['locale' => 'fr'])->save();

        app(CreateShift::class)->handle($this->manager, $this->input(['user_id' => $this->colin->id]));
        app(CreateShift::class)->handle($this->manager, $this->input(['shift_date' => '2026-11-04']));

        [$french, $english] = Mail::mailer('array')->getSymfonyTransport()->messages()->all();

        $this->assertStringContainsString('Nouveau shift assigné', $french->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('Un shift vous a été ajouté', $french->getOriginalMessage()->getHtmlBody());
        $this->assertStringContainsString('New shift assigned', $english->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('A shift was added for you', $english->getOriginalMessage()->getHtmlBody());
        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_stored_notification_is_read_in_the_reader_language(): void
    {
        app(CreateShift::class)->handle($this->manager, $this->input());
        $notification = $this->emma->notifications()->firstOrFail();

        $english = NotificationPresenter::forRecord($notification);
        $this->assertSame('New shift assigned', $english['title']);
        $this->assertSame('A shift was added for you on Mon 2 Nov 2026 from 09:00 to 17:00 (Alpha).', $english['body']);

        app()->setLocale('fr');
        $french = NotificationPresenter::forRecord($notification);
        $this->assertSame('Nouveau shift assigné', $french['title']);
        $this->assertSame('Un shift vous a été ajouté le lun. 2 nov. 2026 de 09:00 à 17:00 (Alpha).', $french['body']);
    }
}
