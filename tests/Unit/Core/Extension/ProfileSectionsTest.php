<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\ProfileSection;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\ProfileSections;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Sections du profil apportées par les bundles (étape 4, contrat 1.12) : déclarations des bundles actifs
 * (Bundle::profileSections()), aucun repli du Core, cases limitées aux colonnes ouvertes, rendu isolé.
 */
final class ProfileSectionsTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/kintai-profile-sections-' . bin2hex(random_bytes(4));
        mkdir($this->viewDir . '/acme', 0777, true);
        file_put_contents($this->viewDir . '/acme/section.php', '<p>acme <?= (int) $user[\'id\'] ?></p>');
        file_put_contents($this->viewDir . '/acme/boom.php', '<?php throw new \RuntimeException("kaput");');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewDir . '/acme/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->viewDir . '/acme');
        rmdir($this->viewDir);
    }

    /** @param array<string, mixed> $active slug => sections déclarées (list, ou \Throwable levée) */
    private function sections(array $active): ProfileSections
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function profileSections(): array
                {
                    if ($this->declared instanceof \Throwable) {
                        throw $this->declared;
                    }
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new ProfileSections(new ExtensionRegistry($manager));
    }

    private static function keys(ProfileSections $sections): array
    {
        return array_map(static fn(ProfileSection $s): string => $s->key, $sections->sections());
    }

    public function testNoActiveBundleMeansNoSectionAndNoFlag(): void
    {
        $sections = $this->sections([]);

        $this->assertSame([], $sections->sections());
        $this->assertSame([], $sections->flags());
        $this->assertSame([], (new ProfileSections())->flags(), 'sans registre : rien');
    }

    /** team-directory actif sans déclaration (version d'avant le contrat 1.12) : le Core n'affiche plus rien. */
    public function testNoFallbackForABundleThatDoesNotDeclare(): void
    {
        $sections = $this->sections(['team-directory' => []]);

        $this->assertSame([], $sections->sections());
        $this->assertSame([], $sections->flags());
    }

    public function testDeclaredSectionIsShownWithItsFlags(): void
    {
        $sections = $this->sections(['team-directory' => [
            new ProfileSection('directory', 'team-directory::profile-section', ['show_in_directory', 'share_email'], 5),
        ]]);

        $this->assertCount(1, $sections->sections());
        $this->assertSame('team-directory::profile-section', $sections->sections()[0]->view);
        $this->assertSame(['show_in_directory', 'share_email'], $sections->flags());
    }

    /** Une colonne hors de la liste ouverte aux bundles n'est jamais enregistrée par le profil. */
    public function testFlagsAreLimitedToWritableUserColumns(): void
    {
        $sections = $this->sections(['acme' => [
            new ProfileSection('acme', 'acme.section', ['is_active', 'share_email', 'email_verified']),
        ]]);

        $this->assertSame(['share_email'], $sections->flags());
    }

    public function testDuplicateKeysOrderAndFailingBundles(): void
    {
        $sections = $this->sections([
            'acme'  => [new ProfileSection('zeta', 'acme.section', order: 50), new ProfileSection('alpha', 'acme.section', order: 50)],
            'other' => [new ProfileSection('zeta', 'other.section', order: 1), new ProfileSection('first', 'acme.section', order: 1)],
            'boom'  => new \RuntimeException('kaput'),
        ]);

        $this->assertSame(['first', 'alpha', 'zeta'], self::keys($sections));
        $this->assertSame('acme.section', $sections->sections()[2]->view, 'la première déclaration de « zeta » est gardée');
    }

    public function testRenderIsolatesAFailingView(): void
    {
        $sections = $this->sections(['acme' => [
            new ProfileSection('ok', 'acme.section', order: 1),
            new ProfileSection('broken', 'acme.boom', order: 2),
            new ProfileSection('missing', 'acme.nowhere', order: 3),
        ]]);

        $html = $sections->render(['id' => 42], new ViewRenderer($this->viewDir));

        $this->assertSame(['<p>acme 42</p>'], $html);
    }

    public function testInvalidDeclarationsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProfileSection('directory', 'x.y', ['Bad-Column']);
    }
}
