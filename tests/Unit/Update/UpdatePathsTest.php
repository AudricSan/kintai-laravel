<?php

namespace Tests\Unit\Update;

use App\Update\UpdatePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpdatePathsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function paths(): array
    {
        return [
            '.env' => ['.env', true],
            '.env upper case' => ['.ENV', true],
            '.env.example is a normal file' => ['.env.example', false],
            'storage root' => ['storage', true],
            'storage file' => ['storage/app/x.txt', true],
            'sqlite database' => ['database/database.sqlite', true],
            'migrations' => ['database/migrations/x.php', false],
            'public storage link' => ['public/storage', true],
            'public storage file' => ['public/storage/a.png', true],
            'public index' => ['public/index.php', false],
            'bootstrap cache' => ['bootstrap/cache/packages.php', true],
            'bootstrap app' => ['bootstrap/app.php', false],
            'git' => ['.git/config', true],
            'storagefoo is not storage' => ['storagefoo/x', false],
            'app' => ['app/Update/UpdatePaths.php', false],
        ];
    }

    #[DataProvider('paths')]
    public function test_protected_paths(string $path, bool $protected): void
    {
        $this->assertSame($protected, (new UpdatePaths(__DIR__))->isProtected($path));
    }
}
