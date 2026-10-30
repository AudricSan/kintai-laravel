<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\UserAnonymizer;
use App\Services\Calendar\CalendarEventBuilder;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleCalendarSettings;
use App\Services\Calendar\GoogleRedirectUri;
use App\Services\CalendarSyncService;
use App\Services\SecretBox;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarLinkController;
use App\Http\Controllers\System\CalendarSettingsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le conteneur résout chaque paramètre de constructeur typé par une classe en l'instanciant : un paramètre `\Closure` ou
 * `callable` y échoue (page /login en erreur 500 sur une instance neuve). Les tests qui construisent les objets à la main
 * ne le voient pas, d'où ce garde-fou sur les classes de la synchronisation d'agenda et celles qui les reçoivent.
 */
final class ContainerResolvableConstructorsTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function classes(): array
    {
        $out = [];
        foreach ([
            CalendarSyncService::class, SecretBox::class, GoogleCalendarClient::class,
            GoogleCalendarSettings::class, GoogleRedirectUri::class, CalendarEventBuilder::class,
            CalendarLinkController::class, CalendarSettingsController::class, UserAnonymizer::class, AuthController::class,
        ] as $class) {
            $out[$class] = [$class];
        }
        return $out;
    }

    /** @param class-string $class */
    #[DataProvider('classes')]
    public function testNoConstructorParameterIsAClosureOrCallable(string $class): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $this->addToAssertionCount(1); // une classe sans constructeur se résout d'elle-même
        foreach ($constructor?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            $names = $type instanceof \ReflectionNamedType ? [$type->getName()] : array_map(
                static fn($t) => $t->getName(),
                $type instanceof \ReflectionUnionType ? $type->getTypes() : [],
            );
            foreach ($names as $name) {
                $this->assertNotContains(strtolower($name), ['closure', 'callable'], "$class::\${$param->getName()}");
            }
        }
    }
}
