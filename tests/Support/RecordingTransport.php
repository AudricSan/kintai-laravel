<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Transport de test : transmet chaque message envoyé (destinataires, sujet, HTML, en-têtes) à un rappel ; un rappel qui
 * renvoie false fait échouer l'envoi, comme un serveur qui refuse le message.
 */
final class RecordingTransport extends AbstractTransport
{
    /** @param \Closure(list<string>, string, string, array<string, string>): bool $onSend */
    public function __construct(private readonly \Closure $onSend)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        /** @var Email $email */
        $email   = $message->getOriginalMessage();
        $headers = [];
        foreach ($email->getHeaders()->all() as $header) {
            $headers[$header->getName()] = $header->getBodyAsString();
        }
        $to = array_map(static fn(Address $address): string => $address->getAddress(), $email->getTo());

        if (($this->onSend)($to, (string) $email->getSubject(), (string) $email->getHtmlBody(), $headers) === false) {
            throw new TransportException('Envoi refusé par le transport de test.');
        }
    }

    public function __toString(): string
    {
        return 'recording://test';
    }
}
