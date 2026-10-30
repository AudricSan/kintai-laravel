<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Validation\ColorRule;
use App\Models\Role;
use App\Models\ShiftType;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 13/10/2026 (M1) : une couleur libre finissait dans un attribut style="" des vues
 * (injection CSS stockée). Seul l'hexadécimal #RGB / #RRGGBB est accepté, à la saisie comme à la lecture.
 */
final class ColorRuleTest extends TestCase
{
    /** @return array<string, array{0: mixed}> */
    public static function validColors(): array
    {
        return ['six chiffres' => ['#6366f1'], 'majuscules' => ['#3B82F6'], 'trois chiffres' => ['#fa0']];
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidColors(): array
    {
        return [
            'injection css'  => ['red;position:fixed;inset:0'],
            'injection html' => ['"><script>alert(1)</script>'],
            'nom de couleur' => ['red'],
            'sans diese'     => ['6366f1'],
            'longueur 4'     => ['#abcd'],
            'longueur 8'     => ['#6366f1ff'],
            'non hexa'       => ['#gggggg'],
            'saut de ligne'  => ["#6366f1\n"],
            'chaine vide'    => [''],
            'null'           => [null],
            'tableau'        => [['#fff']],
        ];
    }

    #[DataProvider('validColors')]
    public function testValidHexColorsAreAccepted(mixed $color): void
    {
        $this->assertTrue(ColorRule::isValid($color));
        $this->assertTrue(Validator::make(['color' => $color], ['color' => [new ColorRule()]])->passes());
        $this->assertSame($color, ColorRule::safe($color));
    }

    #[DataProvider('invalidColors')]
    public function testInvalidColorsAreRejected(mixed $color): void
    {
        $this->assertFalse(ColorRule::isValid($color));
        $this->assertSame('#123456', ColorRule::safe($color, '#123456'));

        $this->assertTrue(Validator::make(['color' => $color], ['color' => ['filled', new ColorRule()]])->fails());
    }

    public function testNullableColorAcceptsNullAndEmptyString(): void
    {
        $rules = ['color' => ['nullable', new ColorRule()]];
        $this->assertTrue(Validator::make(['color' => null], $rules)->passes());
        $this->assertTrue(Validator::make(['color' => ''], $rules)->passes());
        $this->assertTrue(Validator::make(['color' => 'red'], $rules)->fails());
    }

    public function testSafeColorHelperFallsBackOnInvalidValue(): void
    {
        $this->assertSame('#6366f1', safe_color('red;x'));
        $this->assertSame('#abcdef', safe_color('red;x', '#abcdef'));
        $this->assertSame('#112233', safe_color('#112233'));
    }

    public function testModelsReplaceAnInvalidStoredColorAtReadTime(): void
    {
        $user = (new User())->setRawAttributes(['id' => 1, 'color' => 'red;position:fixed']);
        $this->assertSame('#3B82F6', $user->toArray()['color']);

        $type = (new ShiftType())->setRawAttributes(['id' => 1, 'color' => '"><b>']);
        $this->assertSame('#3B82F6', $type->toArray()['color']);

        $role = (new Role())->setRawAttributes(['id' => 1, 'color' => 'url(x)']);
        $this->assertNull($role->toArray()['color']);
    }

    public function testModelsKeepAValidStoredColor(): void
    {
        $this->assertSame('#112233', (new User())->setRawAttributes(['color' => '#112233'])->toArray()['color']);
        $this->assertSame('#abc', (new ShiftType())->setRawAttributes(['color' => '#abc'])->toArray()['color']);
        $this->assertSame('#112233', (new Role())->setRawAttributes(['color' => '#112233'])->toArray()['color']);
    }
}
