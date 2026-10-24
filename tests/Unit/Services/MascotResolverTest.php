<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\MascotResolver;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

final class MascotResolverTest extends TestCase
{
    private function makeResolver(array $stored = []): MascotResolver
    {
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn($stored);
        return new MascotResolver(new AppSettingsService($repo));
    }

    public function testActiveIsAlwaysKitsuneWhenModeFixed(): void
    {
        $resolver = $this->makeResolver(['app_mascot_mode' => 'kitsune']);

        $this->assertSame('kitsune', $resolver->active());
    }

    public function testActiveIsAlwaysTanukiWhenModeFixed(): void
    {
        $resolver = $this->makeResolver(['app_mascot_mode' => 'tanuki']);

        $this->assertSame('tanuki', $resolver->active());
    }

    public function testActiveIsMemoizedWithinTheSameRequest(): void
    {
        $resolver = $this->makeResolver(['app_mascot_mode' => 'mix']);

        $first = $resolver->active();
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, $resolver->active());
        }
    }

    public function testMixPicksBothMascotsAcrossManyResolvers(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[$this->makeResolver(['app_mascot_mode' => 'mix'])->active()] = true;
        }

        $this->assertArrayHasKey('kitsune', $seen);
        $this->assertArrayHasKey('tanuki', $seen);
    }

    public function testPathReturnsKitsuneFileWhenItExists(): void
    {
        $resolver = $this->makeResolver(['app_mascot_mode' => 'kitsune']);

        $this->assertSame('mascot/kitsune/brand-icon.png', $resolver->path('brand-icon'));
    }

    public function testPathReturnsTanukiFileWhenItExists(): void
    {
        $resolver = $this->makeResolver(['app_mascot_mode' => 'tanuki']);

        $this->assertSame('mascot/tanuki/brand-icon.png', $resolver->path('brand-icon'));
    }

    public function testPathFallsBackToKitsuneWhenTanukiAssetMissingForThisContext(): void
    {
        $resolver = $this->makeResolver(['app_mascot_mode' => 'tanuki']);

        // Contexte volontairement inexistant des deux côtés : path() ne doit
        // jamais renvoyer un fichier absent, même en cas de futur écart entre
        // les deux jeux (voir docs/brand/mascot.md section 4).
        $this->assertSame('mascot/kitsune/some-context-that-does-not-exist.png', $resolver->path('some-context-that-does-not-exist'));
    }
}
