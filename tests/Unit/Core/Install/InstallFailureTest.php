<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Install;

use kintai\Core\Install\InstallFailure;
use PDOException;
use PHPUnit\Framework\TestCase;

final class InstallFailureTest extends TestCase
{
    public function testConnectionRefusedReadFromMessage(): void
    {
        $e = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $this->assertSame('install_error_mysql_unreachable', InstallFailure::from($e)->messageKey);
    }

    public function testAccessDeniedReadFromErrorInfo(): void
    {
        $e = new PDOException('Access denied');
        $e->errorInfo = ['28000', 1045, 'Access denied for user'];
        $this->assertSame('install_error_mysql_access_denied', InstallFailure::from($e)->messageKey);
    }

    public function testPdoErrorWrappedInAnotherExceptionIsFound(): void
    {
        $wrapped = new \RuntimeException('Query failed', 0, new PDOException('SQLSTATE[42000] [1044] Access denied to database'));
        $this->assertSame('install_error_mysql_privileges', InstallFailure::from($wrapped)->messageKey);
    }

    public function testAnythingElseIsGenericAndKeepsTheCause(): void
    {
        $cause = new \RuntimeException('SQLSTATE[23000]: UNIQUE constraint failed: users.email … $2y$12$hash');
        $failure = InstallFailure::from($cause);
        $this->assertSame('install_error_generic', $failure->messageKey);
        $this->assertSame($cause, $failure->getPrevious());
        // Le message porté est la clé, jamais le détail technique.
        $this->assertSame('install_error_generic', $failure->getMessage());
    }

    public function testExistingFailureReturnedAsIs(): void
    {
        $failure = new InstallFailure('install_error_not_writable', ['name' => 'config/']);
        $this->assertSame($failure, InstallFailure::from($failure));
    }
}
