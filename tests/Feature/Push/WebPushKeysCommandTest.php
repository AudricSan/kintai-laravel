<?php

namespace Tests\Feature\Push;

use Tests\TestCase;

class WebPushKeysCommandTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = tempnam(sys_get_temp_dir(), 'env');
        $this->app->useEnvironmentPath(dirname($this->envFile));
        $this->app->loadEnvironmentFrom(basename($this->envFile));
        config(['kintai.webpush.public_key' => null, 'kintai.webpush.private_key' => null]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->envFile)) {
            unlink($this->envFile);
        }

        parent::tearDown();
    }

    private function env(): string
    {
        return (string) file_get_contents($this->envFile);
    }

    public function test_keys_are_written_to_the_env_file(): void
    {
        file_put_contents($this->envFile, "APP_NAME=Kintai\nVAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\nOTHER=1\n");

        $this->artisan('kintai:webpush:keys')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}$/m', $this->env());
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}$/m', $this->env());
        $this->assertStringContainsString("APP_NAME=Kintai\n", $this->env());
        $this->assertStringContainsString("OTHER=1\n", $this->env());
    }

    public function test_keys_are_appended_when_absent_from_the_env_file(): void
    {
        file_put_contents($this->envFile, "APP_NAME=Kintai\n");

        $this->artisan('kintai:webpush:keys')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=\S{87}$/m', $this->env());
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=\S{43}$/m', $this->env());
    }

    public function test_existing_keys_are_not_overwritten_without_force(): void
    {
        $original = "VAPID_PUBLIC_KEY=oldpublic\nVAPID_PRIVATE_KEY=oldprivate\n";
        file_put_contents($this->envFile, $original);

        $this->artisan('kintai:webpush:keys')->assertFailed();

        $this->assertSame($original, $this->env());
    }

    public function test_keys_already_configured_outside_the_env_file_are_not_overwritten_either(): void
    {
        file_put_contents($this->envFile, "APP_NAME=Kintai\n");
        config(['kintai.webpush.public_key' => 'a', 'kintai.webpush.private_key' => 'b']);

        $this->artisan('kintai:webpush:keys')->assertFailed();

        $this->assertSame("APP_NAME=Kintai\n", $this->env());
    }

    public function test_force_replaces_existing_keys(): void
    {
        file_put_contents($this->envFile, "VAPID_PUBLIC_KEY=oldpublic\nVAPID_PRIVATE_KEY=oldprivate\n");

        $this->artisan('kintai:webpush:keys', ['--force' => true])->assertSuccessful();

        $this->assertStringNotContainsString('old', $this->env());
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=\S{87}$/m', $this->env());
    }

    public function test_show_prints_the_keys_and_leaves_the_env_file_alone(): void
    {
        file_put_contents($this->envFile, "APP_NAME=Kintai\n");

        $this->artisan('kintai:webpush:keys', ['--show' => true])
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->assertSuccessful();

        $this->assertSame("APP_NAME=Kintai\n", $this->env());
    }

    public function test_generated_keys_have_the_p256_shape(): void
    {
        file_put_contents($this->envFile, '');
        $this->artisan('kintai:webpush:keys')->assertSuccessful();
        preg_match('/^VAPID_PUBLIC_KEY=(\S+)$/m', $this->env(), $public);

        $raw = base64_decode(strtr($public[1], '-_', '+/'));
        $this->assertSame(65, strlen($raw));
        $this->assertSame("\x04", $raw[0]);
    }
}
