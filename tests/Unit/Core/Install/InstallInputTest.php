<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Install;

use kintai\Core\Install\InstallInput;
use kintai\Core\Install\InstallRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallInputTest extends TestCase
{
    private const LANGUAGES = ['fr', 'en', 'ja'];

    /** @param list<string> $missing */
    private function requirements(array $missing = []): InstallRequirements
    {
        return new InstallRequirements(
            sys_get_temp_dir(),
            '8.3.5',
            static fn (string $ext): bool => !in_array($ext, $missing, true),
            static fn (): bool => true,
        );
    }

    /** @param array<string, string> $override */
    private function post(array $override = []): array
    {
        return array_merge([
            'admin_first_name'            => 'Aki',
            'admin_last_name'             => 'Sato',
            'admin_email'                 => 'aki@example.com',
            'admin_password'              => 'password123',
            'admin_password_confirmation' => 'password123',
            'app_locale'                  => 'fr',
            'db_driver'                   => 'sqlite',
        ], $override);
    }

    /** @param array<string, string> $override */
    private function errors(array $override = [], array $missing = []): array
    {
        return InstallInput::fromPost($this->post($override), 'en')->errors(self::LANGUAGES, $this->requirements($missing));
    }

    public function testValidSqliteInstallation(): void
    {
        $this->assertSame([], $this->errors());
    }

    public function testFieldsAreTrimmedAndDemoFlagRead(): void
    {
        $in = InstallInput::fromPost($this->post(['admin_email' => '  aki@example.com ', 'run_seeds' => '1']), 'en');
        $this->assertSame('aki@example.com', $in->email);
        $this->assertTrue($in->demoData);
    }

    public function testMissingLocaleFallsBackToDefault(): void
    {
        $post = $this->post();
        unset($post['app_locale']);
        $this->assertSame('en', InstallInput::fromPost($post, 'en')->locale);
    }

    public function testPasswordConfirmationMustMatch(): void
    {
        $this->assertSame(['install_error_password_mismatch'], $this->errors(['admin_password_confirmation' => 'password124']));
    }

    public function testShortPasswordReportedBeforeMismatch(): void
    {
        $this->assertSame(['install_error_password_length'], $this->errors(['admin_password' => 'short', 'admin_password_confirmation' => 'other']));
    }

    public function testUnknownLanguageRejected(): void
    {
        $this->assertSame(['install_error_language'], $this->errors(['app_locale' => 'de']));
    }

    public function testUnavailableDriverRejected(): void
    {
        $this->assertSame(['install_error_driver_unavailable'], $this->errors(['db_driver' => 'mysql', 'mysql_database' => 'kintai', 'mysql_username' => 'root'], ['pdo_mysql']));
    }

    /** @return array<string, array{string, bool}> */
    public static function databaseNames(): array
    {
        return [
            'simple'            => ['kintai', true],
            'underscore/dollar' => ['kintai_prod$1', true],
            'backtick'          => ['x`; DROP DATABASE y; --', false],
            'tiret'             => ['kintai-prod', false],
            'vide'              => ['', false],
            'trop long'         => [str_repeat('a', 65), false],
        ];
    }

    #[DataProvider('databaseNames')]
    public function testMysqlDatabaseNameRestricted(string $name, bool $valid): void
    {
        $errors = $this->errors(['db_driver' => 'mysql', 'mysql_database' => $name, 'mysql_username' => 'root']);
        $this->assertSame($valid, !in_array('install_error_mysql_database', $errors, true));
    }

    public function testMysqlPortRange(): void
    {
        $errors = $this->errors(['db_driver' => 'mysql', 'mysql_database' => 'kintai', 'mysql_username' => 'root', 'mysql_port' => '70000']);
        $this->assertSame(['install_error_mysql_port'], $errors);
    }

    public function testBlockingRequirementRejectsInstallation(): void
    {
        $this->assertContains('install_error_requirements', $this->errors([], ['mbstring']));
    }
}
