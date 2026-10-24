<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\ShiftFormSection;
use kintai\Core\Container;
use kintai\Core\Services\Log;
use kintai\UI\ViewRenderer;

/**
 * Sections du formulaire de shift apportées par les bundles actifs (point d'extension Bundle::shiftFormSections(),
 * contrat 1.17), par exemple les champs « Shift ouvert » de shift-claim. Le Core n'en affiche aucune de lui-même.
 */
final class ShiftFormSections
{
    /**
     * Colonnes de shifts qu'une section de bundle peut enregistrer. Les autres (magasin, employé, horaires…) restent
     * sous le seul contrôle du Core, quelle que soit la déclaration du bundle.
     */
    public const WRITABLE_SHIFT_COLUMNS = ['is_open', 'open_shift_note'];

    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucune section si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /**
     * Sections des bundles actifs, triées par ordre (une clé déjà prise n'est pas reprise).
     *
     * @return list<ShiftFormSection>
     */
    public function sections(): array
    {
        $all = [];
        foreach ($this->registry?->shiftFormSectionsByBundle() ?? [] as $sections) {
            array_push($all, ...$sections);
        }

        $sections = [];
        foreach ($all as $section) {
            $sections[$section->key] ??= $section;
        }
        $sections = array_values($sections);
        usort($sections, static fn(ShiftFormSection $a, ShiftFormSection $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        return $sections;
    }

    /**
     * Colonnes de shifts que le formulaire peut enregistrer : celles des sections, limitées aux colonnes ouvertes aux
     * bundles (une autre colonne est ignorée et journalisée).
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->sections() as $section) {
            foreach ($section->fields as $field) {
                if (in_array($field, self::WRITABLE_SHIFT_COLUMNS, true)) {
                    $fields[$field] = true;
                } else {
                    $this->log('bundle_extension_invalid', ['point' => 'shiftFormSections', 'key' => $section->key, 'field' => $field]);
                }
            }
        }
        return array_keys($fields);
    }

    /**
     * HTML des champs des sections, pour un shift donné.
     *
     * @param array<string, mixed>   $shift
     * @param callable(string): bool $feat  Fonctionnalité par magasin active ?
     */
    public function renderFields(array $shift, string $mode, callable $feat, ?ViewRenderer $view = null): string
    {
        return $this->render('view', $shift, $mode, $feat, $view);
    }

    /**
     * HTML des boutons des sections dans la barre d'actions (édition seulement).
     *
     * @param array<string, mixed>   $shift
     * @param callable(string): bool $feat
     */
    public function renderActions(array $shift, callable $feat, ?ViewRenderer $view = null): string
    {
        return $this->render('actionsView', $shift, 'edit', $feat, $view);
    }

    private function render(string $which, array $shift, string $mode, callable $feat, ?ViewRenderer $view): string
    {
        try {
            $view ??= Container::getInstance()->make(ViewRenderer::class);
        } catch (\Throwable) {
            return '';
        }
        $html = '';
        foreach ($this->sections() as $section) {
            $name = $section->{$which};
            if ($name === null || ($section->storeFeature !== null && !$feat($section->storeFeature))) {
                continue;
            }
            $level = ob_get_level();
            try {
                $html .= $view->renderPartial($name, ['shift' => $shift, 'mode' => $mode, 'BASE_URL' => base_url()]);
            } catch (\Throwable $e) {
                // Une vue qui lève une exception laisse ouvert le tampon de sortie de renderPartial.
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                $this->log('bundle_extension_failed', ['point' => 'shiftFormSections', 'key' => $section->key, 'error' => $e->getMessage()]);
            }
        }
        return $html;
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, array $context): void
    {
        try {
            Log::warning($event, $context);
        } catch (\Throwable) {
        }
    }
}
