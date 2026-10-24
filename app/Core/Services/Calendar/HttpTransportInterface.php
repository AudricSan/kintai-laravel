<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

/** Transport HTTP minimal des appels vers un fournisseur d'agenda ; remplacé par un double en test. */
interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 quand le serveur n'a pas pu être joint
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 10): array;
}
