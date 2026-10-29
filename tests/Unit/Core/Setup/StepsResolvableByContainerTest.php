<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Le conteneur instancie chaque paramètre de constructeur typé par une classe : un paramètre `\Closure` y échoue (la page
 * /admin/setup répondait 500 sur une instance neuve). Les tests qui construisent les étapes à la main ne le voient pas.
 * Les étapes sont résolues par le conteneur (SetupWizardController) : aucune ne doit demander de Closure ni de callable.
 */
final class StepsResolvableByContainerTest extends TestCase
{
    public function testNoSetupStepConstructorAsksForAClosure(): void
    {
        $files = glob(dirname(__DIR__, 4) . '/app/Setup/*.php') ?: [];
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $class = 'App\\Setup\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $this->assertNotContains(strtolower($type->getName()), ['closure', 'callable'], "$class::\${$param->getName()}");
                }
            }
        }
    }
}
