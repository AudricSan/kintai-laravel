<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\UnsubscribeTokens;
use kintai\Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

/** Signature des liens de désabonnement : propre à (utilisateur, type, canal) et à l'instance. */
final class UnsubscribeTokensTest extends TestCase
{
    public function testASignatureOnlyValidatesWhatItWasMadeFor(): void
    {
        $tokens = new UnsubscribeTokens(new InMemoryAppSettings());
        $sig    = $tokens->sign(7, 'shift_assigned', 'email');

        $this->assertTrue($tokens->verify(7, 'shift_assigned', 'email', $sig));
        $this->assertFalse($tokens->verify(8, 'shift_assigned', 'email', $sig), 'autre utilisateur');
        $this->assertFalse($tokens->verify(7, 'shift_deleted', 'email', $sig), 'autre type');
        $this->assertFalse($tokens->verify(7, 'shift_assigned', 'push', $sig), 'autre canal');
        $this->assertFalse($tokens->verify(7, 'shift_assigned', 'email', strtoupper($sig)), 'signature modifiée');
    }

    public function testEmptyOrMissingInputIsNeverValid(): void
    {
        $tokens = new UnsubscribeTokens(new InMemoryAppSettings());

        $this->assertFalse($tokens->verify(7, 'shift_assigned', 'email', ''));
        $this->assertFalse($tokens->verify(0, 'shift_assigned', 'email', $tokens->sign(0, 'shift_assigned', 'email')));
        $this->assertFalse($tokens->verify(7, '', 'email', $tokens->sign(7, '', 'email')));
    }

    public function testTheSecretIsCreatedOnceAndIsPerInstance(): void
    {
        $settings = new InMemoryAppSettings();
        $tokens   = new UnsubscribeTokens($settings);
        $sig      = $tokens->sign(7, 'shift_assigned', 'email');

        $secret = $settings->get(UnsubscribeTokens::SECRET_KEY);
        $this->assertNotNull($secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $this->assertSame($sig, (new UnsubscribeTokens($settings))->sign(7, 'shift_assigned', 'email'), 'la signature survit entre deux requêtes');
        $this->assertSame($secret, $settings->get(UnsubscribeTokens::SECRET_KEY), 'le secret n\'est pas recréé');

        $other = new UnsubscribeTokens(new InMemoryAppSettings());
        $this->assertNotSame($sig, $other->sign(7, 'shift_assigned', 'email'), 'une autre instance signe autrement');
        $this->assertFalse($other->verify(7, 'shift_assigned', 'email', $sig));
    }
}
