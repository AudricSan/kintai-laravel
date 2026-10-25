<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Foundation;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Validation\Factory;
use kintai\Core\Container;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Services\TranslationService;
use kintai\Foundation\Bridge\KintaiTranslator;
use PHPUnit\Framework\TestCase;

/** Le validateur de Laravel parle avec les messages de validation de Kintai, dans la langue de l'utilisateur. */
final class LaravelValidationBridgeTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = (new \ReflectionProperty(Container::class, 'instance'))->getValue();

        $lang = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/lang/fr.json'), true);
        $translations = $this->createStub(TranslationRepositoryInterface::class);
        $translations->method('findByLocale')->willReturn($lang);
        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findDefault')->willReturn(['code' => 'fr']);

        $service = new TranslationService($translations, $languages);
        $service->setLocale('fr');
        $container = new Container();
        $container->instance(TranslationService::class, $service);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $rules @return array<string, string[]> */
    private function errors(array $data, array $rules): array
    {
        $factory = new Factory(new KintaiTranslator(), new IlluminateContainer());

        return $factory->make($data, $rules)->errors()->toArray();
    }

    public function testRuleMessagesAreKintaiMessages(): void
    {
        $errors = $this->errors(
            ['name' => '', 'code' => str_repeat('x', 12), 'age' => 200, 'mail' => 'nope'],
            ['name' => 'required', 'code' => 'max:10', 'age' => 'integer|max:120', 'mail' => 'email'],
        );

        $this->assertSame(['Le champ « name » est obligatoire.'], $errors['name']);
        $this->assertSame(['Le champ « code » est trop long (10 au maximum).'], $errors['code']);
        $this->assertSame(['Le champ « age » est hors des valeurs permises.'], $errors['age']);
        $this->assertSame(['Le champ « mail » n\'a pas un format valide.'], $errors['mail']);
    }

    public function testOtherKeysAreUnchanged(): void
    {
        $translator = new KintaiTranslator();

        $this->assertSame('validation.attributes', $translator->get('validation.attributes'));
        $this->assertSame('Le champ « x » est obligatoire.', $translator->get('val_field_required', ['field' => 'x']));
    }
}
