<?php

declare(strict_types=1);

namespace kintai\Core\Mail;

/**
 * Client SMTP minimal sans dépendance tierce.
 * Supporte STARTTLS (port 587) et SSL direct (port 465), AUTH LOGIN.
 */
final class SmtpTransport implements MailTransportInterface
{
    /** @var resource|null */
    private mixed $socket = null;

    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly int    $timeout = 30,
    ) {}

    /**
     * @param string[] $to
     * @param string[] $attachmentPaths  Chemins absolus vers les pièces jointes
     */
    public function send(
        string $fromAddress,
        string $fromName,
        array  $to,
        string $subject,
        string $htmlBody,
        array  $attachmentPaths = [],
        array  $extraHeaders = [],
    ): bool {
        try {
            $this->connect();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $fromAddress . '>', [250]);
            foreach ($to as $recipient) {
                $this->command('RCPT TO:<' . trim($recipient) . '>', [250, 251]);
            }

            $this->command('DATA', [354]);
            $this->write($this->buildRawMessage($fromAddress, $fromName, $to, $subject, $htmlBody, $attachmentPaths, $extraHeaders));
            $this->write('.');
            $this->expectCode([250]);

            $this->command('QUIT', [221]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[Kintai][SMTP] ' . $e->getMessage());
            return false;
        } finally {
            if (is_resource($this->socket)) {
                @fclose($this->socket);
                $this->socket = null;
            }
        }

        return true;
    }

    private function connect(): void
    {
        $proto   = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $address = $proto . '://' . $this->host . ':' . $this->port;

        $this->socket = @stream_socket_client(
            $address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT,
        );

        if (!is_resource($this->socket)) {
            throw new \RuntimeException("SMTP connexion échouée vers {$address} : {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->expectCode([220]);

        $domain = gethostname() ?: 'localhost';
        $this->ehlo($domain);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS : impossible d\'activer le chiffrement.');
            }
            $this->ehlo($domain);
        }
    }

    private function ehlo(string $domain): void
    {
        try {
            $this->command('EHLO ' . $domain, [250]);
        } catch (\Throwable) {
            $this->command('HELO ' . $domain, [250]);
        }
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return;
        }
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($this->password), [235]);
    }

    /** @param int[] $expected */
    private function command(string $cmd, array $expected): string
    {
        $this->write($cmd);
        return $this->expectCode($expected);
    }

    /** @param int[] $codes */
    private function expectCode(array $codes): string
    {
        $response = '';
        while (is_resource($this->socket) && ($line = fgets($this->socket, 512)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException("SMTP code inattendu {$code}: " . trim($response));
        }
        return $response;
    }

    private function write(string $data): void
    {
        fwrite($this->socket, $data . "\r\n");
    }

    /** @param string[] $to  @param string[] $attachmentPaths */
    private function buildRawMessage(
        string $fromAddress,
        string $fromName,
        array  $to,
        string $subject,
        string $htmlBody,
        array  $attachmentPaths,
        array  $extraHeaders = [],
    ): string {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader     = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddress . '>'
            : $fromAddress;
        $dateHeader     = date('r');
        $messageId      = '<' . bin2hex(random_bytes(12)) . '@' . ($this->host) . '>';

        if (empty($attachmentPaths)) {
            $raw = implode("\r\n", [
                'Date: ' . $dateHeader,
                'Message-ID: ' . $messageId,
                'From: ' . $fromHeader,
                'To: ' . implode(', ', $to),
                'Subject: ' . $encodedSubject,
                ...MailHeaders::format($extraHeaders),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
                '',
                quoted_printable_encode($htmlBody),
            ]);
            return $this->dotStuff($raw);
        }

        $boundary = '----=_Part_' . bin2hex(random_bytes(8));
        $msg      = implode("\r\n", [
            'Date: ' . $dateHeader,
            'Message-ID: ' . $messageId,
            'From: ' . $fromHeader,
            'To: ' . implode(', ', $to),
            'Subject: ' . $encodedSubject,
            ...MailHeaders::format($extraHeaders),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($htmlBody),
        ]);

        foreach ($attachmentPaths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $filename = basename($path);
            $encoded  = chunk_split(base64_encode((string) file_get_contents($path)));
            $msg .= "\r\n--{$boundary}\r\n"
                . "Content-Type: application/pdf; name=\"{$filename}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
                . "\r\n" . $encoded;
        }

        return $this->dotStuff($msg . "\r\n--{$boundary}--");
    }

    /** RFC 5321 §4.5.2 : toute ligne commençant par '.' doit être préfixée d'un '.' supplémentaire. */
    private function dotStuff(string $data): string
    {
        return preg_replace('/^(\\.)/m', '.$1', $data) ?? $data;
    }
}
