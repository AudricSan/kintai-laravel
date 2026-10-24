<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Validation;

use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Validation\StoreValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class StoreValidatorTest extends TestCase
{
    private LanguageRepositoryInterface&Stub $languages;
    private StoreValidator $validator;

    protected function setUp(): void
    {
        $this->languages = $this->createStub(LanguageRepositoryInterface::class);
        $this->languages->method('findAllActive')->willReturn([['code' => 'en'], ['code' => 'fr'], ['code' => 'ja']]);

        $this->validator = new StoreValidator($this->languages);
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
        $result = $this->validator->validate($this->validData());
        $this->assertTrue($result->isValid());
    }

    public function testCurrencySymbolStyleAcceptsKanjiAndInternational(): void
    {
        $this->assertTrue($this->validator->validate($this->validData(['currency_symbol_style' => 'kanji']))->isValid());
        $this->assertTrue($this->validator->validate($this->validData(['currency_symbol_style' => 'international']))->isValid());
    }

    public function testCurrencySymbolStyleRejectsUnknownValue(): void
    {
        $result = $this->validator->validate($this->validData(['currency_symbol_style' => 'romaji']));
        $this->assertFalse($result->isValid());
    }
}
