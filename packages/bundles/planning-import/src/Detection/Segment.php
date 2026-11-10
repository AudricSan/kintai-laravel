<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 1 : segment horizontal continu détecté dans la feuille, sans logique métier.
 */
final readonly class Segment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $rowNumber,
        public string $date,
        public int $startCol,
        public int $endCol,
        public string $startTime,
        public string $endTime,
        public int $durationMinutes,
        public int $cellCount,
        public ?string $color,
        public bool $hasTopBorder,
        public bool $hasBottomBorder,
        public ?string $employeeName,
        public array $metadata = [],
    ) {}

    /**
     * Fond blanc : aucune couleur, ou blanc / noir pur.
     */
    public function isWhite(): bool
    {
        if ($this->color === null) {
            return true;
        }

        return in_array(strtoupper(trim($this->color)), ['FFFFFFFF', 'FFFFFF', 'FF000000'], true);
    }

    public function hasValidName(): bool
    {
        $name = $this->employeeName === null ? '' : trim($this->employeeName);

        return $name !== '' && ! preg_match('/^\d+$/', $name) && mb_strlen($name) > 1;
    }

    public function withName(?string $name): self
    {
        return new self(
            $this->rowNumber, $this->date, $this->startCol, $this->endCol, $this->startTime, $this->endTime,
            $this->durationMinutes, $this->cellCount, $this->color, $this->hasTopBorder, $this->hasBottomBorder,
            $name, $this->metadata,
        );
    }
}
