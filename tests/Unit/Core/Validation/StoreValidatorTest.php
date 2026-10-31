<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Validation;

use App\Validation\StoreValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class StoreValidatorTest extends TestCase
{
    private StoreValidator $validator;

    protected function setUp(): void
    {
        kintai_test_languages(['en', 'fr', 'ja']);

        $this->validator = new StoreValidator();
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'code'   => 'STORE01',
            'name'   => 'Store 1',
            'locale' => 'en',
        ], $overrides);
    }

    public function testCurrencySymbolStyleDefaultsToValidWhenAbsent(): void
    {
        $result = $this->validator->make($this->validData());
        $this->assertTrue($result->passes());
    }

    public function testCurrencySymbolStyleAcceptsKanjiAndInternational(): void
    {
        $this->assertTrue($this->validator->make($this->validData(['currency_symbol_style' => 'kanji']))->passes());
        $this->assertTrue($this->validator->make($this->validData(['currency_symbol_style' => 'international']))->passes());
    }

    public function testCurrencySymbolStyleRejectsUnknownValue(): void
    {
        $result = $this->validator->make($this->validData(['currency_symbol_style' => 'romaji']));
        $this->assertFalse($result->passes());
    }
}
