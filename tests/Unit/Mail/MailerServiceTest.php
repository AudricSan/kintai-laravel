<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Mail;

use kintai\Core\Mail\MailerService;
use kintai\Core\Mail\MailHeaders;
use kintai\Core\Mail\MailTransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Facade mail : réglage détecté, en-têtes supplémentaires transmis au transport, destinataires filtrés. */
final class MailerServiceTest extends TestCase
{
    /** @return array<string, array{array, bool}> */
    public static function configurations(): array
    {
        return [
            'natif, adresse d\'origine'          => [['driver' => 'native', 'from' => ['address' => 'noreply@example.com']], false],
            'natif, sans configuration'          => [[], false],
            'natif, adresse choisie'             => [['driver' => 'native', 'from' => ['address' => 'kintai@mon-domaine.fr']], true],
            'natif, adresse invalide'            => [['driver' => 'native', 'from' => ['address' => 'pas-un-email']], false],
            'smtp, serveur et identifiant'       => [['driver' => 'smtp', 'smtp' => ['host' => 'smtp.exemple.fr', 'username' => 'kintai']], true],
            'smtp, sans identifiant'             => [['driver' => 'smtp', 'smtp' => ['host' => 'smtp.exemple.fr', 'username' => '']], false],
            'smtp, sans serveur'                 => [['driver' => 'smtp', 'smtp' => ['host' => '', 'username' => 'kintai']], false],
        ];
    }

    #[DataProvider('configurations')]
    public function testIsConfigured(array $config, bool $expected): void
    {
        $this->assertSame($expected, (new MailerService($config, $this->createStub(MailTransportInterface::class)))->isConfigured());
    }

    public function testSendPassesHeadersAndOnlyValidRecipientsToTheTransport(): void
    {
        $transport = $this->createMock(MailTransportInterface::class);
        $transport->expects($this->once())->method('send')->with(
            'kintai@mon-domaine.fr',
            'Kintai',
            ['a@exemple.fr'],
            'Sujet',
            '<p>Corps</p>',
            [],
            ['List-Unsubscribe' => '<https://exemple.fr/u>'],
        )->willReturn(true);
        $mailer = new MailerService(['from' => ['address' => 'kintai@mon-domaine.fr', 'name' => 'Kintai']], $transport);

        $this->assertTrue($mailer->send(['a@exemple.fr', 'pas-un-email'], 'Sujet', '<p>Corps</p>', [], ['List-Unsubscribe' => '<https://exemple.fr/u>'], 5));
    }

    public function testSendWithoutAnyValidRecipientSendsNothing(): void
    {
        $transport = $this->createMock(MailTransportInterface::class);
        $transport->expects($this->never())->method('send');

        $this->assertFalse((new MailerService([], $transport))->send(['pas-un-email'], 'Sujet', 'Corps'));
    }

    public function testHeadersWithLineBreaksAreDropped(): void
    {
        $lines = MailHeaders::format([
            'List-Unsubscribe'      => '<https://exemple.fr/u>',
            'X-Injection'           => "ok\r\nBcc: victime@exemple.fr",
            "X-Nom\r\nBcc"          => 'valeur',
            'Nom invalide'          => 'valeur',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        $this->assertSame(['List-Unsubscribe: <https://exemple.fr/u>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'], $lines);
    }
}
