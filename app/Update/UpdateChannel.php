<?php

namespace App\Update;

/**
 * Canal de mise à jour : quelles versions sont proposées. Release : stables seulement ; Beta : stables, beta et rc ; Alpha : tout.
 */
enum UpdateChannel: string
{
    case Release = 'release';
    case Beta = 'beta';
    case Alpha = 'alpha';

    public function accepts(Version $version): bool
    {
        return match ($this) {
            self::Release => $version->channel() === 'release',
            self::Beta => $version->channel() !== 'alpha',
            self::Alpha => true,
        };
    }
}
