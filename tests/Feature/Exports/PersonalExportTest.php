<?php

namespace Tests\Feature\Exports;

use App\Actions\Exports\ExportOwnSchedule;
use App\Models\ActivityEntry;
use App\Models\Availability;
use App\Models\EmployeeProfile;
use App\Models\IcalToken;
use App\Models\NotificationPreference;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class PersonalExportTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha', 'code' => 'ALP']);
        $this->employee = $this->employeeOf($this->store, ['name' => 'Anna']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(User $user, string $date, array $attributes = []): Shift
    {
        return Shift::factory()->create($attributes + [
            'user_id' => $user->id,
            'store_id' => $this->store->id,
            'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'),
            'ends_at' => Shift::localTime($date, '17:00'),
            'pause_minutes' => 60,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function exportSchedule(array $parameters)
    {
        return $this->actingAs($this->employee)->get(route('schedule-export.download', ['store' => $this->store->id] + $parameters));
    }

    public function test_the_schedule_is_exported_as_json_for_a_period(): void
    {
        $type = ShiftType::factory()->create(['name' => 'Morning']);
        $this->shift($this->employee, '2026-11-10', ['shift_type_id' => $type->id, 'notes' => 'Keys']);
        $this->shift($this->employee, '2026-12-30');
        $this->shift($this->employeeOf($this->store), '2026-11-11');

        $response = $this->exportSchedule(['format' => 'json', 'from' => '2026-11-01', 'to' => '2026-11-30']);

        $response->assertOk();
        $this->assertStringContainsString('kintai-ALP-2026-11-01_2026-11-30.json', $response->headers->get('Content-Disposition'));
        $data = json_decode($response->streamedContent(), true);
        $this->assertSame(['id' => $this->store->id, 'name' => 'Alpha', 'timezone' => 'UTC'], $data['store']);
        $this->assertSame(['2026-11-01', '2026-11-30', 1], [$data['from'], $data['to'], $data['count']]);
        $this->assertSame('2026-11-10', $data['shifts'][0]['date']);
        $this->assertSame('Morning', $data['shifts'][0]['shift_type']);
        $this->assertSame(420, $data['shifts'][0]['net_minutes']);
        $this->assertSame('Keys', $data['shifts'][0]['notes']);
        $this->assertSame('Anna', $data['shifts'][0]['employee']);
    }

    public function test_the_schedule_is_exported_as_csv_and_ics(): void
    {
        $shift = $this->shift($this->employee, '2026-11-10');

        $csv = $this->exportSchedule(['format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('2026-11-10;09:00;17:00', $body);

        $ics = $this->exportSchedule(['format' => 'ics', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $ics->assertOk();
        $this->assertStringStartsWith('text/calendar', $ics->headers->get('Content-Type'));
        $this->assertStringContainsString('UID:'.$shift->ical_uid, $ics->streamedContent());
    }

    public function test_the_period_defaults_to_90_days_and_is_capped(): void
    {
        $export = app(ExportOwnSchedule::class);
        $today = CarbonImmutable::parse('2026-11-05');

        [$from, $to] = $export->period(null, null, $today);
        $this->assertSame(['2026-11-05', '2027-02-03'], [$from->toDateString(), $to->toDateString()]);

        [$from, $to] = $export->period('2026-11-30', '2026-11-01', $today);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$from->toDateString(), $to->toDateString()]);

        [$from, $to] = $export->period('2026-01-01', '2030-01-01', $today);
        $this->assertSame('2027-01-02', $to->toDateString());

        [$from] = $export->period('2026-02-30', null, $today);
        $this->assertSame('2026-11-05', $from->toDateString());
    }

    public function test_only_the_user_own_schedule_in_a_store_they_belong_to_can_be_exported(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colleague']);
        $this->shift($colleague, '2026-11-10');
        $other = Store::factory()->create();

        $this->exportSchedule(['format' => 'json', 'from' => '2026-11-01', 'to' => '2026-11-30', 'user' => $colleague->id, 'users' => [$colleague->id]])
            ->assertOk();
        $data = json_decode($this->exportSchedule(['format' => 'json', 'from' => '2026-11-01', 'to' => '2026-11-30', 'user' => $colleague->id])->streamedContent(), true);
        $this->assertSame(0, $data['count']);

        $this->actingAs($this->employee)->get(route('schedule-export.download', ['store' => $other->id, 'format' => 'json']))->assertNotFound();
        $this->exportSchedule(['format' => 'xml'])->assertSessionHasErrors('format');
    }

    public function test_the_schedule_export_requires_a_login_and_is_logged(): void
    {
        $this->get(route('schedule-export.download', ['store' => $this->store->id, 'format' => 'csv']))->assertRedirect(route('login'));

        $this->exportSchedule(['format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->streamedContent();

        $entry = ActivityEntry::where('action', 'export.schedule')->sole();
        $this->assertSame($this->employee->id, $entry->actor_id);
        $this->assertSame('csv', $entry->properties['format']);
    }

    public function test_personal_data_export_contains_the_user_data_and_no_secret(): void
    {
        $employee = User::factory()->withTwoFactor()->create(['name' => 'Anna Lovelace', 'email' => 'anna@example.com', 'employee_code' => 'E1']);
        $this->store->members()->attach($employee, ['staff_code' => 'S-9', 'hire_date' => '2024-04-01', 'contract_type' => 'part-time']);
        $employee->assignRole($this->role('employee'), $this->store);
        EmployeeProfile::factory()->create(['user_id' => $employee->id, 'phone' => '03-1234', 'birth_date' => '1990-05-17', 'guarantor_name' => 'Parent']);
        $this->shift($employee, '2026-11-10', ['notes' => 'Keys']);
        Availability::factory()->create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'day_of_week' => 2]);
        NotificationPreference::create(['user_id' => $employee->id, 'type' => 'shift.created', 'channel' => 'mail', 'enabled' => false]);
        IcalToken::factory()->forToken('super-secret-ical-token')->create(['user_id' => $employee->id, 'store_id' => $this->store->id]);
        $employee->forceFill(['remember_token' => 'remember-me-secret'])->save();
        $employee->webPushSubscriptions()->create(['endpoint' => 'https://push.example/endpoint-secret', 'endpoint_hash' => hash('sha256', 'x'), 'p256dh' => 'p256dh-secret', 'auth' => 'auth-secret']);
        $employee->devicePushTokens()->create(['token' => 'fcm-token-secret', 'platform' => 'ios']);

        $response = $this->actingAs($employee)->get(route('data-export.download'));

        $response->assertOk();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('kintai-data-'.$employee->id.'.json', $response->headers->get('Content-Disposition'));
        $raw = $response->streamedContent();
        $data = json_decode($raw, true);

        $this->assertSame('Anna Lovelace', $data['identity']['name']);
        $this->assertSame('anna@example.com', $data['identity']['email']);
        $this->assertSame('E1', $data['identity']['employee_code']);
        $this->assertSame('03-1234', $data['profile']['phone']);
        $this->assertSame('1990-05-17', $data['profile']['birth_date']);
        $this->assertSame('Parent', $data['profile']['guarantor_name']);
        $this->assertSame([['store' => 'Alpha', 'store_code' => 'ALP', 'staff_code' => 'S-9', 'hire_date' => '2024-04-01', 'contract_type' => 'part-time', 'is_active' => true]], $data['memberships']);
        $this->assertSame([['role' => 'Employee', 'store' => 'Alpha']], array_map(fn (array $r): array => ['role' => $r['role'], 'store' => $r['store']], $data['roles']));
        $this->assertSame('2026-11-10', $data['shifts'][0]['date']);
        $this->assertSame('Keys', $data['shifts'][0]['notes']);
        $this->assertSame(2, $data['availabilities'][0]['day_of_week']);
        $this->assertSame([['type' => 'shift.created', 'channel' => 'mail', 'enabled' => false]], $data['notification_preferences']);

        $employeeRecord = $employee->fresh();
        foreach ([
            $employeeRecord->password, $employeeRecord->two_factor_secret, $employeeRecord->two_factor_recovery_codes,
            'remember-me-secret', 'super-secret-ical-token', 'endpoint-secret', 'p256dh-secret', 'auth-secret', 'fcm-token-secret',
            hash('sha256', 'super-secret-ical-token'),
        ] as $secret) {
            $this->assertNotEmpty($secret);
            $this->assertStringNotContainsString((string) $secret, $raw);
        }
        foreach (['password', 'two_factor', 'token', 'secret'] as $word) {
            $this->assertStringNotContainsString($word, strtolower(implode(' ', array_keys($data['identity']))));
        }
        $this->assertSame(1, ActivityEntry::where('action', 'export.personal_data')->count());
    }

    public function test_personal_data_only_contains_the_data_of_the_signed_in_user(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colleague', 'email' => 'colleague@example.com']);
        $this->shift($colleague, '2026-11-10');

        $raw = $this->actingAs($this->employee)->get(route('data-export.download'))->streamedContent();

        $this->assertStringNotContainsString('Colleague', $raw);
        $this->assertSame([], json_decode($raw, true)['shifts']);
    }

    public function test_personal_pages_render_and_the_data_page_is_linked_from_the_settings(): void
    {
        $this->actingAs($this->employee)->get(route('data.edit'))->assertOk()->assertSee(route('data-export.download'));
        $this->get(route('calendar.edit'))->assertOk()->assertSee('Export my schedule');
        $this->get(route('profile.edit'))->assertSee(route('data.edit'));
    }
}
