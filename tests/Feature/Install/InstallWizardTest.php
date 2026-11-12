<?php

namespace Tests\Feature\Install;

use App\Models\User;
use Tests\Concerns\UsesTemporaryInstallation;
use Tests\TestCase;

class InstallWizardTest extends TestCase
{
    use UsesTemporaryInstallation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInstallation();
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        $this->get('/install');

        return array_replace(
            $this->ownerInput(['password_confirmation' => 'Correct-Horse-Battery-9', 'db_driver' => 'sqlite']),
            ['setup_token' => is_file($this->installDirectory.'/install-token') ? trim((string) file_get_contents($this->installDirectory.'/install-token')) : 'none'],
            $overrides,
        );
    }

    public function test_the_form_is_shown_with_the_requirements(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('PHP 8.3.0');
    }

    public function test_a_missing_or_wrong_token_refuses_the_installation(): void
    {
        $this->post('/install', $this->form(['setup_token' => 'wrong']))->assertSessionHasErrors('install');
        $this->post('/install', array_diff_key($this->form(), ['setup_token' => 1]))->assertSessionHasErrors('install');

        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
        $this->assertFileDoesNotExist($this->installDirectory.'/.env');
    }

    public function test_the_token_is_removed_once_installed(): void
    {
        $this->post('/install', $this->form())->assertRedirect(route('login'));

        $this->assertFileDoesNotExist($this->installDirectory.'/install-token');
    }

    public function test_installs_then_redirects_to_login_and_closes_the_wizard(): void
    {
        $this->post('/install', $this->form())->assertRedirect(route('login'));

        $this->assertFileExists($this->installDirectory.'/installed');
        $this->assertTrue(User::query()->where('email', 'owner@example.test')->firstOrFail()->roles->contains('slug', 'owner'));

        $this->get('/install')->assertNotFound();
        $this->post('/install', $this->form(['email' => 'second@example.test']))->assertNotFound();
    }

    public function test_invalid_input_goes_back_with_errors_and_keeps_no_password(): void
    {
        $this->from('/install')
            ->post('/install', $this->form(['email' => 'not-an-email', 'db_password' => 'secret-db-pass']))
            ->assertRedirect('/install')
            ->assertSessionHasErrors('email');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('db_password'));
        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
    }

    public function test_everything_redirects_to_the_wizard_until_installed(): void
    {
        config(['kintai.install.enforce' => true]);

        $this->get('/login')->assertRedirect(route('install.show'));
        $this->get('/dashboard')->assertRedirect(route('install.show'));
        $this->get('/install')->assertOk();
    }

    public function test_nothing_redirects_once_installed(): void
    {
        config(['kintai.install.enforce' => true]);
        file_put_contents($this->installDirectory.'/installed', 'x');

        $this->get('/login')->assertOk();
        $this->get('/install')->assertNotFound();
    }
}
