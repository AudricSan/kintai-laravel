<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Validation;

use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Moteur de validation des corps de l'API (audit du 13/10/2026, M9). */
final class PayloadValidatorTest extends TestCase
{
    private const RULES = [
        'name'   => ['type' => 'string', 'required' => true, 'max' => 10],
        'bio'    => ['type' => 'text', 'nullable' => true, 'max' => 50],
        'age'    => ['type' => 'int', 'min' => 0, 'max' => 120],
        'rate'   => ['type' => 'number', 'min' => 0],
        'active' => ['type' => 'bool'],
        'day'    => ['type' => 'date', 'nullable' => true],
        'start'  => ['type' => 'time'],
        'mail'   => ['type' => 'email'],
        'kind'   => ['type' => 'enum', 'in' => ['a', 'b']],
        'ref'    => ['type' => 'id', 'nullable' => true],
        'tags'   => ['type' => 'list', 'max' => 3],
        'color'  => ['type' => 'color'],
    ];

    private function errorsOf(array $data, bool $partial = false): array
    {
        try {
            PayloadValidator::validate($data, self::RULES, $partial);
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('Une ValidationException était attendue.');
    }

    public function testUnknownAndTechnicalFieldsAreDropped(): void
    {
        $clean = PayloadValidator::validate(['name' => 'Ana', 'id' => 5, 'password_hash' => 'x', 'created_at' => 'y'], self::RULES);

        $this->assertSame(['name' => 'Ana'], $clean);
    }

    public function testRequiredFieldsAreEnforcedOnCreationOnly(): void
    {
        $this->assertArrayHasKey('name', $this->errorsOf([]));
        $this->assertSame(['age' => 30], PayloadValidator::validate(['age' => 30], self::RULES, true));
    }

    public function testARequiredFieldCannotBeEmptiedOnUpdate(): void
    {
        $this->assertArrayHasKey('name', $this->errorsOf(['name' => '  '], true));
    }

    public function testValuesAreNormalized(): void
    {
        $clean = PayloadValidator::validate([
            'name' => '  Ana ', 'age' => '42', 'rate' => '12.5', 'active' => 'true', 'start' => '09:30:00',
            'bio' => '', 'day' => '2026-10-20', 'kind' => 'b', 'ref' => null, 'tags' => ['x', 'x', 'y'], 'color' => '#abc',
        ], self::RULES);

        $this->assertSame([
            'name' => 'Ana', 'bio' => null, 'age' => 42, 'rate' => 12.5, 'active' => 1, 'day' => '2026-10-20',
            'start' => '09:30', 'kind' => 'b', 'ref' => null, 'tags' => ['x', 'y'], 'color' => '#abc',
        ], $clean);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidValues(): array
    {
        return [
            'chaine trop longue'       => ['name', 'abcdefghijk'],
            'retour a la ligne'        => ['name', "Ana\nBob"],
            'tableau pour une chaine'  => ['name', ['Ana']],
            'entier hors bornes'       => ['age', 200],
            'entier decimal'           => ['age', 1.5],
            'booleen pour un entier'   => ['age', true],
            'nombre negatif'           => ['rate', -1],
            'nombre en texte'          => ['rate', 'douze'],
            'booleen inconnu'          => ['active', 'oui'],
            'date impossible'          => ['day', '2026-02-30'],
            'date mal formee'          => ['day', '20/10/2026'],
            'heure impossible'         => ['start', '24:00'],
            'email invalide'           => ['mail', 'pas-un-email'],
            'enum inconnu'             => ['kind', 'c'],
            'identifiant nul'          => ['ref', 0],
            'liste trop longue'        => ['tags', ['a', 'b', 'c', 'd']],
            'liste associative'        => ['tags', ['k' => 'v']],
            'liste non textuelle'      => ['tags', [1, 2]],
            'couleur invalide'         => ['color', 'red;x'],
            'texte trop long'          => ['bio', str_repeat('a', 51)],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejectedPerField(string $field, mixed $value): void
    {
        $errors = $this->errorsOf(['name' => 'Ana', $field => $value]);

        $this->assertSame([$field], array_keys($errors));
    }

    public function testAllErrorsAreReportedAtOnce(): void
    {
        $this->assertEqualsCanonicalizing(['name', 'age', 'kind'], array_keys($this->errorsOf(['age' => -1, 'kind' => 'z'])));
    }

    public function testAnUpdateThatChangesNothingIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        PayloadValidator::validateUpdate(['id' => 3, 'unknown' => 1], self::RULES);
    }

    public function testShiftTimingIsDerivedFromDateAndHours(): void
    {
        $this->assertSame(
            ['duration_minutes' => 480, 'cross_midnight' => 0, 'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 17:00:00'],
            ApiPayloadRules::shiftTiming('2026-10-20', '09:00', '17:00'),
        );
        $this->assertSame(
            ['duration_minutes' => 360, 'cross_midnight' => 1, 'starts_at' => '2026-10-31 22:00:00', 'ends_at' => '2026-11-01 04:00:00'],
            ApiPayloadRules::shiftTiming('2026-10-31', '22:00', '04:00'),
        );
    }
}
