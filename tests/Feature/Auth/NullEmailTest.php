<?php

namespace Tests\Feature\Auth;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NullEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_several_accounts_can_have_no_email(): void
    {
        User::factory()->create(['email' => null]);
        User::factory()->create(['email' => '  ']);

        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_account_without_email_signs_in_with_employee_code(): void
    {
        $store = Store::factory()->create(['code' => 'SHIBUYA']);
        $user = User::factory()->create(['email' => null, 'employee_code' => 'E1']);
        $store->members()->attach($user);

        $this->post(route('login.store'), ['login' => 'E1', 'store_code' => 'SHIBUYA', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_empty_login_never_matches_an_account_without_email(): void
    {
        User::factory()->create(['email' => null]);

        $this->post(route('login.store'), ['login' => '', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_password_reset_request_gives_the_same_answer_and_sends_nothing(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);
        User::factory()->create(['email' => null, 'employee_code' => 'E1']);

        $known = $this->post(route('password.request'), ['email' => 'known@example.com']);
        $unknown = $this->post(route('password.request'), ['email' => 'nobody@example.com']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        Notification::assertSentToTimes(User::where('email', 'known@example.com')->firstOrFail(), ResetPassword::class, 1);
        Notification::assertCount(1);
    }
}
