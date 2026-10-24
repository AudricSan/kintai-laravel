<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Components;

use kintai\UI\Components\Flash;
use PHPUnit\Framework\TestCase;

/**
 * Flash::fromQuery() n'affiche que les messages prévus par la vue : afficher la valeur brute du paramètre permettait
 * à n'importe qui de fabriquer un lien (?success=…) montrant le texte de son choix dans un bandeau Kintai.
 */
final class FlashFromQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testAKnownValueShowsItsMessage(): void
    {
        $_GET = ['success' => 'created'];

        $html = Flash::fromQuery('success', ['created' => 'Créé avec succès'])->render();

        $this->assertStringContainsString('Créé avec succès', $html);
    }

    public function testAnUnknownValueShowsNothingAndNeverTheRawText(): void
    {
        $_GET = ['success' => 'Votre compte est suspendu, appelez le 0800'];

        $html = Flash::fromQuery('success', ['created' => 'Créé'])->render();

        $this->assertSame('', $html);
    }

    public function testTheDefaultMessageIsUsedForAnyOtherValue(): void
    {
        // Les réglages Owner redirigent vers ?success=1 et déclarent ['default' => …] : le bandeau affichait « 1 ».
        $_GET = ['success' => '1'];

        $html = Flash::fromQuery('success', ['default' => 'Enregistré'])->render();

        $this->assertStringContainsString('Enregistré', $html);
        $this->assertStringNotContainsString('>1<', $html);
    }

    public function testTheDefaultMessageAlsoHidesAnArbitraryText(): void
    {
        $_GET = ['success' => '<b>piège</b>'];

        $html = Flash::fromQuery('success', ['default' => 'Enregistré'])->render();

        $this->assertStringContainsString('Enregistré', $html);
        $this->assertStringNotContainsString('piège', $html);
    }

    public function testNoParameterShowsNothing(): void
    {
        $this->assertSame('', Flash::fromQuery('success', ['default' => 'Enregistré'])->render());
    }
}