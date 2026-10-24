<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Components;

use PHPUnit\Framework\TestCase;
use kintai\UI\Components\Badge;

/**
 * Régression : DailyReport\Views\daily-reports.php réimplémentait sa propre table
 * statut → classe CSS au lieu de passer par Badge::status(), qui ne reconnaissait
 * pas ses statuts ('draft'/'submitted'/'validated'). Ces tests figent le mapping
 * pour que ce composant partagé reste la source de vérité unique.
 */
final class BadgeTest extends TestCase
{
    public function testStatusSubmittedRendersAsPendingVariant(): void
    {
        $html = Badge::make('Soumis')->status('submitted')->render();
        $this->assertStringContainsString('badge--pending', $html);
    }

    public function testStatusValidatedRendersAsSuccessVariant(): void
    {
        $html = Badge::make('Validé')->status('validated')->render();
        $this->assertStringContainsString('badge--success', $html);
    }

    public function testStatusDraftFallsBackToNeutralVariant(): void
    {
        // 'draft' n'a pas de correspondance explicite — doit retomber sur le
        // variant neutre par défaut, visuellement identique à l'ancien badge--secondary.
        $html = Badge::make('Brouillon')->status('draft')->render();
        $this->assertStringContainsString('badge--neutral', $html);
    }

    public function testStatusApprovedStillRendersAsSuccessVariant(): void
    {
        $html = Badge::make('Approuvé')->status('approved')->render();
        $this->assertStringContainsString('badge--success', $html);
    }

    public function testStatusPendingStillRendersAsPendingVariant(): void
    {
        $html = Badge::make('En attente')->status('pending')->render();
        $this->assertStringContainsString('badge--pending', $html);
    }

    public function testLabelIsHtmlEscaped(): void
    {
        $html = Badge::make('<script>alert(1)</script>')->status('pending')->render();
        $this->assertStringNotContainsString('<script>', $html);
    }
}
