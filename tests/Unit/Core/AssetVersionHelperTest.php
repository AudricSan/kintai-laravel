<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * asset_version() : cache-busting auto-calculé (hash du mtime le plus récent
 * sous public/assets/css|js), plus rien à bumper à la main. Mémoïsé pour la
 * durée du process (static), donc on ne peut pas observer un changement de
 * fichier dans le même test — on vérifie juste le contrat : stable, court,
 * hexadécimal, jamais un littéral codé en dur type "v5". Voir helpers.php.
 */
final class AssetVersionHelperTest extends TestCase
{
    public function testReturnsStableValueAcrossCalls(): void
    {
        $this->assertSame(asset_version(), asset_version());
    }

    public function testFormatIsAShortHexToken(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', asset_version());
    }

    public function testIsNotTheOldHardcodedLiteral(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^v\d+$/', asset_version());
    }
}
