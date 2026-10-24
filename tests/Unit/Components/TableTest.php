<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Components;

use kintai\UI\Components\Table;
use PHPUnit\Framework\TestCase;

/**
 * Table::column() insère le HTML renvoyé par son callback tel quel (contrat voulu : badges,
 * boutons, liens) ; Table::text() est la variante qui échappe automatiquement. Ces tests figent
 * les deux comportements pour qu'une donnée saisie par un utilisateur ne puisse pas devenir du
 * HTML (XSS stocké) en passant par les colonnes de texte.
 */
final class TableTest extends TestCase
{
    private const PAYLOAD = '<script>alert(1)</script>';

    public function testTextColumnEscapesHtml(): void
    {
        $html = Table::make()
            ->data([['name' => self::PAYLOAD]])
            ->text('Nom', fn(array $r) => $r['name'])
            ->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testTextColumnEscapesQuotesSoTheyCannotBreakAttributes(): void
    {
        $html = Table::make()
            ->data([['name' => '" onmouseover="x']])
            ->text('Nom', fn(array $r) => $r['name'])
            ->render();

        $this->assertStringNotContainsString('" onmouseover="x', $html);
        $this->assertStringContainsString('&quot; onmouseover=&quot;x', $html);
    }

    public function testTextColumnAcceptsNullAndNumbers(): void
    {
        $html = Table::make()
            ->data([['a' => null, 'b' => 42]])
            ->text('A', fn(array $r) => $r['a'])
            ->text('B', fn(array $r) => $r['b'])
            ->render();

        $this->assertStringContainsString('<td data-label="B">42</td>', $html);
        $this->assertStringContainsString('<td data-label="A"></td>', $html);
    }

    public function testTextColumnReceivesTheRowIndex(): void
    {
        $html = Table::make()
            ->data(['x' => ['name' => 'a'], 'y' => ['name' => 'b']])
            ->text('Clé', fn(array $r, $i) => $i . ':' . $r['name'])
            ->render();

        $this->assertStringContainsString('x:a', $html);
        $this->assertStringContainsString('y:b', $html);
    }

    public function testSortableTextColumnEscapesHtml(): void
    {
        $html = Table::make()
            ->data([['name' => self::PAYLOAD]])
            ->sortableText('Nom', 'name', fn(array $r) => $r['name'])
            ->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testColumnKeepsHtmlRawByContract(): void
    {
        // column() est le choix explicite pour du HTML voulu : il ne doit PAS échapper.
        $html = Table::make()
            ->data([['id' => 1]])
            ->column('Action', fn(array $r) => '<a href="/x/' . (int) $r['id'] . '">Voir</a>')
            ->render();

        $this->assertStringContainsString('<a href="/x/1">Voir</a>', $html);
    }

    public function testColumnLabelIsAlwaysEscaped(): void
    {
        $html = Table::make()
            ->data([['v' => 1]])
            ->text(self::PAYLOAD, fn(array $r) => $r['v'])
            ->render();

        $this->assertStringNotContainsString('<script>', $html);
    }
}
