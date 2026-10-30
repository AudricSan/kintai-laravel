<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\MailerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Service mail : réglage détecté, en-têtes supplémentaires transmis au transport, destinataires filtrés. */
final class MailerServiceTest extends TestCase
{
    /** @return array<string, array{array, bool}> */
    public static function configurations(): array
    {
        $smtp = static fn(string $host, string $username): array => ['default' => 'smtp', 'mailers' => ['smtp' => ['host' => $host, 'username' => $username]]];

        return [
            'sendmail, adresse d origine'        => [['default' => 'sendmail', 'from' => ['address' => 'noreply@example.com']], false],
            'sendmail, sans configuration'       => [[], false],
            'sendmail, adresse choisie'          => [['default' => 'sendmail', 'from' => ['address' => 'kintai@mon-domaine.fr']], true],
            'sendmail, adresse invalide'         => [['default' => 'sendmail', 'from' => ['address' => 'pas-un-email']], false],
            'smtp, serveur et identifiant'       => [$smtp('smtp.exemple.fr', 'kintai'), true],
            'smtp, sans identifiant'             => [$smtp('smtp.exemple.fr', ''), false],
            'smtp, sans serveur'                 => [$smtp('', 'kintai'), false],
        ];
    }

    #[DataProvider('configurations')]
    public function testIsConfigured(array $config, bool $expected): void
    {
        $this->assertSame($expected, (new MailerService($config))->isConfigured());
    }

    public function testSendPassesHeadersAndOnlyValidRecipientsToTheTransport(): void
    {
        $sent   = [];
        $mailer = new MailerService(
            ['from' => ['address' => 'kintai@mon-domaine.fr', 'name' => 'Kintai']],
            kintai_test_mailer(function (array $to, string $subject, string $html, array $headers) use (&$sent): bool {
                $sent = compact('to', 'subject', 'html', 'headers');
                return true;
            }),
        );

        $this->assertTrue($mailer->send(['a@exemple.fr', 'pas-un-email'], 'Sujet', '<p>Corps</p>', [], ['List-Unsubscribe' => '<https://exemple.fr/u>'], 5));
        $this->assertSame(['a@exemple.fr'], $sent['to']);
        $this->assertSame('Sujet', $sent['subject']);
        $this->assertSame('<p>Corps</p>', $sent['html']);
        $this->assertSame('<https://exemple.fr/u>', $sent['headers']['List-Unsubscribe']);
        $this->assertNull($mailer->lastError());
    }

    public function testSendWithoutAnyValidRecipientSendsNothing(): void
    {
        $called = false;
        $mailer = new MailerService([], kintai_test_mailer(function () use (&$called): bool {
            $called = true;
            return true;
        }));

        $this->assertFalse($mailer->send(['pas-un-email'], 'Sujet', 'Corps'));
        $this->assertFalse($called);
    }

    public function testAFailingTransportReturnsFalseAndKeepsTheError(): void
    {
        $mailer = new MailerService([], kintai_test_mailer(static fn(): bool => false));

        $this->assertFalse($mailer->send(['a@exemple.fr'], 'Sujet', 'Corps'));
        $this->assertNotNull($mailer->lastError());
    }

    public function testHeadersWithLineBreaksAreDropped(): void
    {
        $headers = [];
        $mailer  = new MailerService([], kintai_test_mailer(function (array $to, string $subject, string $html, array $h) use (&$headers): bool {
            $headers = $h;
            return true;
        }));

        $mailer->send(['a@exemple.fr'], 'Sujet', 'Corps', [], [
            'List-Unsubscribe'      => '<https://exemple.fr/u>',
            'X-Injection'           => "ok\r\nBcc: victime@exemple.fr",
            "X-Nom\r\nBcc"          => 'valeur',
            'Nom invalide'          => 'valeur',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        $this->assertSame('<https://exemple.fr/u>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertArrayNotHasKey('X-Injection', $headers);
        $this->assertArrayNotHasKey('Bcc', $headers);
        $this->assertArrayNotHasKey('Nom invalide', $headers);
    }
}