<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Console;

use kintai\Console\KintaiScriptCommand;
use PHPUnit\Framework\TestCase;

final class KintaiScriptCommandTest extends TestCase
{
    public function testEveryCommandRunsAnExistingScript(): void
    {
        foreach (KintaiScriptCommand::SCRIPTS as $name => [$script]) {
            $this->assertFileExists(dirname(__DIR__, 3) . '/scripts/' . $script, $name);
        }
    }

    public function testTheCommandPassesItsArgumentsAndExitCodeThrough(): void
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/artisan') . ' kintai:check-wiki /nonexistent-wiki-dir 2>&1', $out, $code);
        $direct = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/check-wiki.php') . ' /nonexistent-wiki-dir 2>&1', $direct, $directCode);

        $this->assertSame($directCode, $code);
        $this->assertSame($direct, $out);
    }
}
