<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\ShiftFormSection;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\ShiftFormSections;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Sections du formulaire de shift apportées par les bundles (contrat 1.17) : déclarations des bundles actifs
 * (Bundle::shiftFormSections()), aucune section du Core, champs limités aux colonnes ouvertes, fonctionnalité par
 * magasin, rendu isolé.
 */
final class ShiftFormSectionsTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/kintai-shift-sections-' . bin2hex(random_bytes(4));
        mkdir($this->viewDir . '/acme', 0777, true);
        file_put_contents($this->viewDir . '/acme/fields.php', '<p>acme <?= $mode ?> <?= (int) ($shift[\'id\'] ?? 0) ?></p>');
        file_put_contents($this->viewDir . '/acme/actions.php', '<button>acme</button>');
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
    private function sections(array $active): ShiftFormSections
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

                public function shiftFormSections(): array
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

        return new ShiftFormSections(new ExtensionRegistry($manager));
    }

    private function view(): ViewRenderer
    {
        $view = new ViewRenderer($this->viewDir);
        $view->addNamespace('acme', $this->viewDir . '/acme');
        return $view;
    }

    public function testDeclaredSectionsAreRenderedInOrderWithTheirFields(): void
    {
        $sections = $this->sections(['acme' => [
            new ShiftFormSection('second', 'acme::fields', ['open_shift_note'], order: 20),
            new ShiftFormSection('first', 'acme::fields', ['is_open', 'user_id'], order: 10, actionsView: 'acme::actions'),
            new ShiftFormSection('broken', 'acme::boom', order: 30),
            'pas une section',
        ], 'boom' => new \RuntimeException('kaput')]);
        $all = fn(string $f): bool => true;

        $this->assertSame(['first', 'second', 'broken'], array_map(fn(ShiftFormSection $s): string => $s->key, $sections->sections()));
        $this->assertSame(['is_open', 'open_shift_note'], $sections->fields(), 'user_id : colonne non ouverte aux bundles');
        $this->assertSame('<p>acme edit 7</p><p>acme edit 7</p>', $sections->renderFields(['id' => 7], 'edit', $all, $this->view()));
        $this->assertSame('<button>acme</button>', $sections->renderActions(['id' => 7], $all, $this->view()));
    }

    public function testStoreFeatureHidesTheSection(): void
    {
        $sections = $this->sections(['acme' => [new ShiftFormSection('board', 'acme::fields', storeFeature: 'acme_board')]]);

        $this->assertSame('', $sections->renderFields([], 'create', fn(string $f): bool => false, $this->view()));
        $this->assertSame('<p>acme create 0</p>', $sections->renderFields([], 'create', fn(string $f): bool => true, $this->view()));
    }

    /** Le Core n'a aucune section de lui-même, même avec shift-claim actif sans déclaration. */
    public function testNoSectionWithoutDeclaration(): void
    {
        $this->assertSame([], $this->sections([])->sections());
        $this->assertSame([], $this->sections(['shift-claim' => []])->sections());
        $this->assertSame([], $this->sections(['shift-claim' => []])->fields());
    }

    public function testInvalidSectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShiftFormSection('Bad Key', 'acme::fields');
    }
}
