<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\SecretBox;
use kintai\Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

/** Chiffrement des secrets gardés en base (jeton Google, secret client). */
final class SecretBoxTest extends TestCase
{
    public function testRoundTripAndTheStoredValueIsNeverThePlainText(): void
    {
        $box    = new SecretBox(new InMemoryAppSettings());
        $stored = $box->encrypt('1//refresh-token');

        $this->assertStringStartsWith('enc1:', $stored);
        $this->assertStringNotContainsString('refresh-token', $stored);
        $this->assertSame('1//refresh-token', $box->decrypt($stored));
    }

    public function testTwoEncryptionsOfTheSameTextDiffer(): void
    {
        $box = new SecretBox(new InMemoryAppSettings());

        $this->assertNotSame($box->encrypt('x'), $box->encrypt('x'));
    }

    public function testASecretEncryptedByAnotherInstanceIsRefused(): void
    {
        $stored = (new SecretBox(new InMemoryAppSettings()))->encrypt('token');

        $this->assertNull((new SecretBox(new InMemoryAppSettings()))->decrypt($stored));
    }

    public function testATamperedValueIsRefused(): void
    {
        $settings = new InMemoryAppSettings();
        $box      = new SecretBox($settings);
        $stored   = $box->encrypt('token');
        $broken   = substr($stored, 0, -4) . 'AAAA';

        $this->assertNull($box->decrypt($broken));
        $this->assertNull($box->decrypt('enc1:pas-du-base64!'));
    }

    public function testAValueWithoutPrefixIsReadAsIs(): void
    {
        $this->assertSame('legacy', (new SecretBox(new InMemoryAppSettings()))->decrypt('legacy'));
    }

    public function testTheInstanceSecretIsCreatedOnceAndReused(): void
    {
        $settings = new InMemoryAppSettings();
        $box      = new SecretBox($settings);
        $stored   = $box->encrypt('a');
        $secret   = $settings->get('instance_secret');

        $this->assertNotNull($secret);
        $this->assertSame('a', (new SecretBox($settings))->decrypt($stored));
        $this->assertSame($secret, $settings->get('instance_secret'));
    }
}
