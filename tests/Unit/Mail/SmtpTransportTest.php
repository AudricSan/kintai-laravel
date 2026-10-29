<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Transport SMTP sur Symfony Mailer : message construit, en-têtes filtrés, échec rapporté à la page de test. */
final class SmtpTransportTest extends TestCase
{
    private function transport(?TransportInterface $inner = null): SmtpTransport
    {
        return new SmtpTransport('smtp.exemple.fr', 465, 'kintai', 'secret', 'ssl', 5, $inner !== null ? static fn() => $inner : null);
    }

    public function testTheMessageCarriesSenderRecipientsSubjectAndHtml(): void
    {
        $email = $this->transport()->message('kintai@mon-domaine.fr', 'Kintai Équipe', ['a@exemple.fr', ' b@exemple.fr '], 'Planning publié — 来週', '<p>Bonjour</p>');

        $this->assertSame('kintai@mon-domaine.fr', $email->getFrom()[0]->getAddress());
        $this->assertSame('Kintai Équipe', $email->getFrom()[0]->getName());
        $this->assertSame(['a@exemple.fr', 'b@exemple.fr'], array_map(static fn($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('Planning publié — 来週', $email->getSubject());
        $this->assertSame('<p>Bonjour</p>', $email->getHtmlBody());
        // Sujet non ASCII encodé dans le message brut.
        $this->assertStringNotContainsString('来週', $email->toString());
    }

    public function testEachAttachmentKeepsItsOwnType(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-smtp-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/planning.csv', "a;b\n1;2\n");
        file_put_contents($dir . '/rapport.pdf', "%PDF-1.4\n");
        try {
            $email = $this->transport()->message('kintai@mon-domaine.fr', 'Kintai', ['a@exemple.fr'], 'Pièces', '<p>x</p>', [$dir . '/planning.csv', $dir . '/rapport.pdf', $dir . '/absent.pdf']);

            $types = [];
            foreach ($email->getAttachments() as $part) {
                $types[$part->getFilename()] = $part->getMediaType() . '/' . $part->getMediaSubtype();
            }
            // L'ancien client SMTP déclarait toute pièce jointe en application/pdf ; un fichier absent est ignoré.
            $this->assertSame(['planning.csv' => 'text/csv', 'rapport.pdf' => 'application/pdf'], $types);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    public function testExtraHeadersAreAddedAndInjectionAttemptsDropped(): void
    {
        $email = $this->transport()->message('kintai@mon-domaine.fr', 'Kintai', ['a@exemple.fr'], 'Sujet', '<p>x</p>', [], [
            'List-Unsubscribe'      => '<https://exemple.fr/u>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Bad'                 => "valeur\r\nBcc: pirate@exemple.fr",
        ]);

        $headers = $email->getHeaders();
        $this->assertSame('<https://exemple.fr/u>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());
        $this->assertFalse($headers->has('X-Bad'));
        $this->assertFalse($headers->has('Bcc'));
    }

    public function testSendHandsTheMessageToTheServer(): void
    {
        $inner = new class implements TransportInterface {
            public ?Email $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent = $message instanceof Email ? $message : null;
                return null;
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };
        $transport = $this->transport($inner);

        $this->assertTrue($transport->send('kintai@mon-domaine.fr', 'Kintai', ['a@exemple.fr'], 'Sujet', '<p>x</p>'));
        $this->assertSame('Sujet', $inner->sent?->getSubject());
        $this->assertNull($transport->getLastError());
    }

    public function testAFailureIsReportedNotThrown(): void
    {
        $inner = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new \RuntimeException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".');
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };
        $transport = $this->transport($inner);

        $previous = ini_set('error_log', '/dev/null');
        try {
            $this->assertFalse($transport->send('kintai@mon-domaine.fr', 'Kintai', ['a@exemple.fr'], 'Sujet', '<p>x</p>'));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString('Authentication failed', (string) $transport->getLastError());
    }

    public function testAnUnreachableServerFailsWithinTheTimeout(): void
    {
        // Port fermé en local : refus immédiat, message d'erreur lisible pour la page de test d'e-mail.
        $transport = new SmtpTransport('127.0.0.1', 1, '', '', 'tls', 2);

        $previous = ini_set('error_log', '/dev/null');
        try {
            $started = microtime(true);
            $this->assertFalse($transport->send('kintai@mon-domaine.fr', 'Kintai', ['a@exemple.fr'], 'Sujet', '<p>x</p>'));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertNotSame('', (string) $transport->getLastError());
    }
}
