<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Security;

use kintai\Core\Security\AttemptCounter;
use PHPUnit\Framework\TestCase;

final class AttemptCounterTest extends TestCase
{
    private string $dir;
    private AttemptCounter $counter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-attempts-' . bin2hex(random_bytes(4));
        $this->counter = new AttemptCounter($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** Écrit directement des horodatages pour une clé (pour simuler des tentatives anciennes). */
    private function seed(string $key, array $timestamps): void
    {
        @mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/' . hash('sha256', $key) . '.lock', json_encode($timestamps));
    }

    public function testCountStartsAtZeroAndGrowsWithEachHit(): void
    {
        $this->assertSame(0, $this->counter->count('k', 60));
        $this->assertSame(1, $this->counter->hit('k', 60));
        $this->assertSame(2, $this->counter->hit('k', 60));
        $this->assertSame(2, $this->counter->count('k', 60));
    }

    public function testKeysAreIndependent(): void
    {
        $this->counter->hit('a', 60);
        $this->counter->hit('a', 60);
        $this->counter->hit('b', 60);

        $this->assertSame(2, $this->counter->count('a', 60));
        $this->assertSame(1, $this->counter->count('b', 60));
    }

    public function testClearResetsTheCounter(): void
    {
        $this->counter->hit('k', 60);
        $this->counter->hit('k', 60);

        $this->counter->clear('k');

        $this->assertSame(0, $this->counter->count('k', 60));
    }

    public function testClearOnAnUnknownKeyIsHarmless(): void
    {
        $this->counter->clear('never-used');
        $this->assertSame(0, $this->counter->count('never-used', 60));
    }

    public function testAttemptsOlderThanTheWindowAreIgnored(): void
    {
        $this->seed('k', [time() - 500, time() - 400, time() - 10]);

        $this->assertSame(1, $this->counter->count('k', 300));
        $this->assertSame(3, $this->counter->count('k', 600));
    }

    public function testHitDropsStaleAttemptsFromTheStoredList(): void
    {
        $this->seed('k', [time() - 900, time() - 800]);

        $this->assertSame(1, $this->counter->hit('k', 300));
    }

    public function testRetryAfterIsZeroBelowTheThreshold(): void
    {
        $this->counter->hit('k', 60);
        $this->counter->hit('k', 60);

        $this->assertSame(0, $this->counter->retryAfter('k', 3, 60));
    }

    public function testRetryAfterIsPositiveOnceTheThresholdIsReached(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->counter->hit('k', 60);
        }

        $retry = $this->counter->retryAfter('k', 3, 60);

        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(60, $retry);
    }

    public function testRetryAfterWaitsForTheOldestAttemptThatMatters(): void
    {
        // Seuil de 3 sur 100 s ; 4 tentatives il y a 90, 50, 20 et 5 s.
        $now = time();
        $this->seed('k', [$now - 90, $now - 50, $now - 20, $now - 5]);

        // Pour repasser sous 3, il suffit que les 2 plus anciennes sortent : la 2e (-50 s) sort dans 50 s.
        $retry = $this->counter->retryAfter('k', 3, 100);

        $this->assertGreaterThanOrEqual(49, $retry);
        $this->assertLessThanOrEqual(51, $retry);
    }

    public function testCorruptedFileIsTreatedAsEmpty(): void
    {
        @mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/' . hash('sha256', 'k') . '.lock', '{not json');

        $this->assertSame(0, $this->counter->count('k', 60));
        $this->assertSame(1, $this->counter->hit('k', 60));
    }

    public function testUnwritableStorageDoesNotBlockTheSite(): void
    {
        // Le « dossier » est un fichier : impossible d'y écrire, mais rien ne doit lever d'exception.
        $file = sys_get_temp_dir() . '/kintai-not-a-dir-' . bin2hex(random_bytes(4));
        file_put_contents($file, 'x');
        try {
            $counter = new AttemptCounter($file);
            $counter->hit('k', 60);
            $this->assertSame(0, $counter->retryAfter('k', 1, 60));
        } finally {
            @unlink($file);
        }
    }
}
