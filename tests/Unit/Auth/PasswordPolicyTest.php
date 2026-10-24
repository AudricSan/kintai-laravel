<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use kintai\Core\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testMinimumIsEightCharacters(): void
    {
        $this->assertSame(8, PasswordPolicy::MIN_LENGTH);
    }

    public function testBoundary(): void
    {
        $this->assertFalse(PasswordPolicy::isLongEnough(str_repeat('a', 7)));
        $this->assertTrue(PasswordPolicy::isLongEnough(str_repeat('a', 8)));
        $this->assertFalse(PasswordPolicy::isLongEnough(''));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // 7 caractères japonais font 21 octets : ils ne doivent pas passer pour 8 caractères.
        $this->assertFalse(PasswordPolicy::isLongEnough('あいうえおかき'));
        $this->assertTrue(PasswordPolicy::isLongEnough('あいうえおかきく'));
    }

    public function testDefaultAccountPasswordIsNotCoveredByThePolicy(): void
    {
        // Décision assumée : « 0000 » reste le mot de passe attribué à la création, signalé par un avertissement.
        $this->assertFalse(PasswordPolicy::isLongEnough('0000'));
    }
}
