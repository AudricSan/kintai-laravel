<?php

declare(strict_types=1);

namespace Tests\Unit\Components;

use App\View\Components\Flash;
use PHPUnit\Framework\TestCase;

/**
 * Flash::fromSession() n'affiche que les messages prévus par la vue : afficher la valeur brute du paramètre permettait
 * à n'importe qui de fabriquer un lien montrant le texte de son choix dans un bandeau Kintai.
 */
final class FlashFromSessionTest extends TestCase
{
    protected function tearDown(): void
    {
        session()->forget(['success', 'error']);
    }

    public function testAKnownValueShowsItsMessage(): void
    {
        session()->put('success', 'created');

        $html = Flash::fromSession('success', ['created' => 'Créé avec succès'])->render();

        $this->assertStringContainsString('Créé avec succès', $html);
    }

    public function testAnUnknownValueShowsNothingAndNeverTheRawText(): void
    {
        session()->put('success', 'Votre compte est suspendu, appelez le 0800');

        $html = Flash::fromSession('success', ['created' => 'Créé'])->render();

        $this->assertSame('', $html);
    }

    public function testTheDefaultMessageIsUsedForAnyOtherValue(): void
    {
        // Les réglages Owner flashent success=1 et déclarent ['default' => …] : le bandeau affichait « 1 ».
        session()->put('success', '1');

        $html = Flash::fromSession('success', ['default' => 'Enregistré'])->render();

        $this->assertStringContainsString('Enregistré', $html);
        $this->assertStringNotContainsString('>1<', $html);
    }

    public function testTheDefaultMessageAlsoHidesAnArbitraryText(): void
    {
        session()->put('success', '<b>piège</b>');

        $html = Flash::fromSession('success', ['default' => 'Enregistré'])->render();

        $this->assertStringContainsString('Enregistré', $html);
        $this->assertStringNotContainsString('piège', $html);
    }

    public function testNoFlashShowsNothing(): void
    {
        $this->assertSame('', Flash::fromSession('success', ['default' => 'Enregistré'])->render());
    }
}