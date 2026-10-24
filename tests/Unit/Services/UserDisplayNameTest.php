<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\UserDisplayName;
use PHPUnit\Framework\TestCase;

final class UserDisplayNameTest extends TestCase
{
    public function testAnEnteredNameIsKeptTrimmed(): void
    {
        $this->assertSame('Aiko', UserDisplayName::resolve('  Aiko ', 'Aiko', 'Sato'));
    }

    public function testAnEmptyNameBecomesLastNameThenFirstName(): void
    {
        $this->assertSame('佐藤 愛子', UserDisplayName::resolve('   ', ' 愛子 ', '佐藤'));
        $this->assertSame('Sato Aiko', UserDisplayName::resolve(null, 'Aiko', 'Sato'));
        $this->assertSame('Sato', UserDisplayName::resolve('', '', 'Sato'));
        $this->assertSame('', UserDisplayName::resolve('', null, null));
    }

    public function testTheNameIsCutAtTheMaximumLength(): void
    {
        $this->assertSame(UserDisplayName::MAX_LENGTH, mb_strlen(UserDisplayName::resolve(str_repeat('名', 250), 'a', 'b')));
    }
}
