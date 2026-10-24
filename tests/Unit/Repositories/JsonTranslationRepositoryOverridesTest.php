<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use kintai\Core\Repositories\JsonTranslationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Contrat des traductions (1.25) : un bundle surcharge une clé du Core uniquement de façon explicite
 * (lang/overrides/{locale}.json), avec les mêmes paramètres « :nom » ; une redéfinition non déclarée est une collision.
 */
final class JsonTranslationRepositoryOverridesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kintai_transl_ovr_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/lang', 0755, true);
        file_put_contents($this->root . '/lang/fr.json', json_encode([
            'save' => 'Enregistrer', 'greeting' => 'Bonjour :name', 'cancel' => 'Annuler',
        ]));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function bundle(string $slug, array $own, array $overrides = []): string
    {
        $dir = $this->root . '/bundles/' . $slug;
        mkdir($dir . '/lang/overrides', 0755, true);
        file_put_contents($dir . '/lang/fr.json', json_encode($own));
        if ($overrides !== []) {
            file_put_contents($dir . '/lang/overrides/fr.json', json_encode($overrides));
        }
        return $dir;
    }

    private function repo(array $bundleRoots): JsonTranslationRepository
    {
        return new JsonTranslationRepository($this->root . '/lang', null, $bundleRoots);
    }

    public function testDeclaredOverrideReplacesTheCoreValue(): void
    {
        $dir  = $this->bundle('acme', ['acme_title' => 'Acme'], ['save' => 'Sauvegarder']);
        $repo = $this->repo([$dir]);

        $this->assertSame('Sauvegarder', $repo->findValue('fr', 'save'));
        $this->assertSame('Acme', $repo->findValue('fr', 'acme_title'));
        $this->assertSame([], $repo->undeclaredCollisions('fr'), 'une surcharge déclarée n\'est pas une collision');
        $this->assertSame(['save' => [$dir]], $repo->declaredOverrides('fr'));
    }

    public function testOverrideChangingThePlaceholdersIsRefused(): void
    {
        $dir  = $this->bundle('acme', [], ['greeting' => 'Salut :user']);
        $repo = $this->repo([$dir]);

        $this->assertSame('Bonjour :name', $repo->findValue('fr', 'greeting'));
    }

    public function testOverrideKeepingThePlaceholdersIsAccepted(): void
    {
        $dir  = $this->bundle('acme', [], ['greeting' => 'Salut :name !']);

        $this->assertSame('Salut :name !', $this->repo([$dir])->findValue('fr', 'greeting'));
    }

    public function testUndeclaredCollisionIsListedAndToleratedByDefault(): void
    {
        $dir  = $this->bundle('acme', ['cancel' => 'Abandonner']);
        $repo = $this->repo([$dir]);

        $this->assertSame(['cancel' => [$dir]], $repo->undeclaredCollisions('fr'));
        $this->assertSame('Abandonner', $repo->findValue('fr', 'cancel'), 'transition : le bundle l\'emporte encore');
    }

    public function testStrictModeIgnoresUndeclaredCollisionsButKeepsDeclaredOverrides(): void
    {
        $dir  = $this->bundle('acme', ['cancel' => 'Abandonner', 'acme_x' => 'X'], ['save' => 'Sauvegarder']);
        $repo = $this->repo([$dir]);
        $repo->strictCollisions(true);

        $this->assertSame('Annuler', $repo->findValue('fr', 'cancel'), 'le Core l\'emporte');
        $this->assertSame('Sauvegarder', $repo->findValue('fr', 'save'));
        $this->assertSame('X', $repo->findValue('fr', 'acme_x'));
    }

    public function testTwoBundlesOverridingTheSameKeyFollowTheSlugOrder(): void
    {
        $a = $this->bundle('aaa', [], ['save' => 'A']);
        $b = $this->bundle('bbb', [], ['save' => 'B']);

        $this->assertSame('B', $this->repo([$b, $a])->findValue('fr', 'save'), 'ordre déterministe, indépendant de l\'ordre de chargement');
        $this->assertSame(['save' => [$a, $b]], $this->repo([$b, $a])->declaredOverrides('fr'));
    }

    public function testPlaceholdersHelperIgnoresTimesAndSorts(): void
    {
        $this->assertSame([':a', ':b'], JsonTranslationRepository::placeholders(':b à 09:00 puis :a :a'));
        $this->assertSame([], JsonTranslationRepository::placeholders('Aucun'));
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }
        @rmdir($dir);
    }
}
