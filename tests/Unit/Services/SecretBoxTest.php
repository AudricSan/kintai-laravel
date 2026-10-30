<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SecretBox;
use Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

/** Chiffrement des secrets gardés en base (jeton Google, secret client). */
final class SecretBoxTest extends TestCase
{
    public function testRoundTripAndTheStoredValueIsNeverThePlainText(): void
    {
        $box    = (new InMemoryAppSettings())->into(new SecretBox());
        $stored = $box->encrypt('1//refresh-token');

        $this->assertStringStartsWith('enc1:', $stored);
        $this->assertStringNotContainsString('refresh-token', $stored);
        $this->assertSame('1//refresh-token', $box->decrypt($stored));
    }

    public function testTwoEncryptionsOfTheSameTextDiffer(): void
    {
        $box = (new InMemoryAppSettings())->into(new SecretBox());

        $this->assertNotSame($box->encrypt('x'), $box->encrypt('x'));
    }

    public function testASecretEncryptedByAnotherInstanceIsRefused(): void
    {
        $stored = ((new InMemoryAppSettings())->into(new SecretBox()))->encrypt('token');

        $this->assertNull(((new InMemoryAppSettings())->into(new SecretBox()))->decrypt($stored));
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
        $this->assertSame('legacy', ((new InMemoryAppSettings())->into(new SecretBox()))->decrypt('legacy'));
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
