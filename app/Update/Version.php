<?php

namespace App\Update;

use InvalidArgumentException;

/**
 * Version sémantique de Kintai : `1.2.3`, avec pré-version facultative `-alpha.N`, `-beta.N` ou `-rc.N` (préfixe `v` toléré).
 * Ordre : alpha < beta < rc < version stable, puis par numéro de pré-version.
 */
final class Version
{
    private const RANKS = ['alpha' => 0, 'beta' => 1, 'rc' => 2];

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly ?string $pre,
        public readonly int $preNumber,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $version): self
    {
        if (preg_match('/^v?(\d{1,9})\.(\d{1,9})\.(\d{1,9})(?:-(alpha|beta|rc)\.(\d{1,9}))?$/', trim($version), $m) !== 1) {
            throw new InvalidArgumentException('Invalid version: '.$version);
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], ($m[4] ?? '') === '' ? null : $m[4], (int) ($m[5] ?? 0));
    }

    public static function tryParse(string $version): ?self
    {
        try {
            return self::parse($version);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** 'release' (stable), 'beta' (beta ou rc) ou 'alpha'. */
    public function channel(): string
    {
        return match ($this->pre) {
            null => 'release',
            'alpha' => 'alpha',
            default => 'beta',
        };
    }

    /** -1, 0 ou 1. */
    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch, $this->rank(), $this->preNumber]
            <=> [$other->major, $other->minor, $other->patch, $other->rank(), $other->preNumber];
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function __toString(): string
    {
        $base = $this->major.'.'.$this->minor.'.'.$this->patch;

        return $this->pre === null ? $base : $base.'-'.$this->pre.'.'.$this->preNumber;
    }

    private function rank(): int
    {
        return $this->pre === null ? 3 : self::RANKS[$this->pre];
    }
}
