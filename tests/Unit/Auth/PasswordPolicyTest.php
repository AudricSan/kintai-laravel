<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\PasswordPolicy;
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
        // Un mot de passe trop court est refusé au changement ; « 0000 » n'est plus attribué à personne.
        $this->assertFalse(PasswordPolicy::isLongEnough('0000'));
    }
    public function testTemporaryPasswordsAreRandomAndLongEnough(): void
    {
        $a = PasswordPolicy::temporary();
        $b = PasswordPolicy::temporary();

        $this->assertTrue(PasswordPolicy::isLongEnough($a));
        $this->assertSame(12, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $a);
    }
}
