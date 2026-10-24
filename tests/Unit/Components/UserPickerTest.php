<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Components;

use kintai\UI\Components\UserPicker;
use PHPUnit\Framework\TestCase;

/**
 * Composant partagé « employés à cocher » : il émet un champ `nom[]` par personne, échappe tout ce qu'il affiche et
 * n'utilise ni style ni script en ligne (CSP à nonce).
 */
final class UserPickerTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function candidates(): array
    {
        return [
            ['id' => 2, 'name' => 'Aki Tanaka', 'email' => 'aki@example.test'],
            ['id' => 5, 'name' => '<b>Léa</b> "Roy"', 'email' => 'lea@example.test'],
        ];
    }

    public function testEachCandidateBecomesACheckboxOfTheNamedArrayField(): void
    {
        $html = UserPicker::make('mail_recipient_ids', $this->candidates())->render();

        $this->assertStringContainsString('name="mail_recipient_ids[]" value="2"', $html);
        $this->assertStringContainsString('name="mail_recipient_ids[]" value="5"', $html);
        $this->assertSame(2, substr_count($html, 'type="checkbox"'));
        $this->assertStringContainsString('aki@example.test', $html);
    }

    public function testOnlyTheSelectedOnesAreChecked(): void
    {
        $html = UserPicker::make('ids', $this->candidates())->selected(['5'])->render();

        $this->assertStringNotContainsString('value="2" checked', $html);
        $this->assertStringContainsString('value="5" checked', $html);
    }

    public function testNamesAreEscaped(): void
    {
        $html = UserPicker::make('ids', $this->candidates())->render();

        $this->assertStringNotContainsString('<b>Léa</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Léa&lt;/b&gt; &quot;Roy&quot;', $html);
    }

    public function testLegendAndHintAreOptionalAndEscaped(): void
    {
        $this->assertStringNotContainsString('user-picker__legend', UserPicker::make('ids', $this->candidates())->render());

        $html = UserPicker::make('ids', $this->candidates())->legend('À qui <envoyer> ?')->hint('Astuce & conseil')->render();
        $this->assertStringContainsString('À qui &lt;envoyer&gt; ?', $html);
        $this->assertStringContainsString('Astuce &amp; conseil', $html);
    }

    public function testWithoutCandidateAnEmptyMessageReplacesTheList(): void
    {
        $html = UserPicker::make('ids', [])->emptyMessage('Personne ici')->render();

        $this->assertStringContainsString('Personne ici', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString('data-user-picker-all', $html);
    }

    public function testTheMarkupIsWiredByDataAttributesOnly(): void
    {
        $html = UserPicker::make('ids', $this->candidates())->render();

        $this->assertStringContainsString('data-user-picker', $html);
        $this->assertStringContainsString('data-user-picker-all', $html);
        $this->assertStringContainsString('data-user-picker-none', $html);
        $this->assertStringNotContainsString('style="', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);
    }

    public function testChipsAndSearchHooksAreDataAttributesAndTheCheckboxesCarryTheirLabels(): void
    {
        $html = UserPicker::make('ids', $this->candidates())->render();

        foreach (['data-user-picker-chips', 'data-user-picker-search', 'data-user-picker-menu'] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
        $this->assertStringContainsString('data-name="Aki Tanaka"', $html, 'le JavaScript lit le nom sur la case');
        $this->assertStringContainsString('data-detail="aki@example.test"', $html);
    }

    public function testTheOwnerIsTaggedOnTheCheckboxAndInTheList(): void
    {
        $html = UserPicker::make('ids', [['id' => 1, 'name' => 'Zoé', 'email' => 'z@example.test', 'owner' => true], ['id' => 2, 'name' => 'Aki']])->render();

        $this->assertSame(1, substr_count($html, 'data-owner='));
        $this->assertSame(1, substr_count($html, 'user-picker__tag'));
    }

    public function testExtraAttributesAndClassesAreMerged(): void
    {
        $html = UserPicker::make('ids', $this->candidates())->attrs(['class' => 'mb-3', 'id' => 'recipients'])->render();

        $this->assertStringContainsString('class="user-picker mb-3"', $html);
        $this->assertStringContainsString('id="recipients"', $html);
    }
}
