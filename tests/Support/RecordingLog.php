<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Remplaçant du gestionnaire de journaux de Laravel pour les tests : retient chaque entrée au lieu de l'écrire.
 * Installé par kintai_test_log() ; la façade Log et AuditLogger l'utilisent sans le savoir.
 */
final class RecordingLog
{
    /** @var list<array{channel: string, level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    private string $channel = 'business';

    public function channel(?string $name = null): self
    {
        $scoped          = new self();
        $scoped->entries = &$this->entries;
        $scoped->channel = $name ?? 'business';

        return $scoped;
    }

    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = ['channel' => $this->channel, 'level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Entrées d'un canal donné.
     *
     * @return list<array{channel: string, level: string, message: string, context: array<string, mixed>}>
     */
    public function on(string $channel): array
    {
        return array_values(array_filter($this->entries, static fn(array $e): bool => $e['channel'] === $channel));
    }

    public function __call(string $method, array $arguments): void
    {
        $this->log($method, $arguments[0] ?? '', $arguments[1] ?? []);
    }
}
