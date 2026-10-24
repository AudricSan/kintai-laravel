<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

/**
 * Questionnaire de l'étape « Bundles » : quelques questions en langage métier qui recommandent des bundles, dans un ordre
 * de priorité. Les questions des bundles officiels viennent de config/setup-questions.php ; un bundle tiers ajoute la
 * sienne par le champ `setup_question` de son entrée de registry, sans toucher au Core.
 *
 * Seules les questions dont au moins un bundle figure au catalogue sont posées.
 */
final class SetupQuestionnaire
{
    public const THEMES = ['planning', 'requests', 'communication', 'reports', 'hr', 'other'];

    /** @param list<array{id: string, bundles: list<string>, icon?: string, theme?: string}> $questions */
    public function __construct(private readonly array $questions) {}

    public static function fromConfig(string $file): self
    {
        $questions = is_file($file) ? require $file : [];
        return new self(is_array($questions) ? $questions : []);
    }

    /**
     * Questions à poser pour ce catalogue, dans l'ordre : officielles puis tierces.
     *
     * @param list<array<string, mixed>> $catalog entrées du catalogue (slug, name, description, setup_question?)
     * @return list<array{id: string, text: string, hint: string, bundles: list<string>, icon: string, theme: string}>
     */
    public function questions(array $catalog, string $locale): array
    {
        $available = [];
        foreach ($catalog as $entry) {
            $available[(string) $entry['slug']] = $entry;
        }

        $out = [];
        $covered = [];
        foreach ($this->questions as $question) {
            $bundles = array_values(array_filter($question['bundles'], static fn(string $slug): bool => isset($available[$slug])));
            if ($bundles === []) {
                continue;
            }
            $id = (string) $question['id'];
            $out[] = [
                'id'      => $id,
                'text'    => __('setup_q_' . $id),
                'hint'    => __('setup_q_' . $id . '_hint'),
                'bundles' => $bundles,
                'icon'    => (string) ($question['icon'] ?? '📦'),
                'theme'   => $this->theme($question['theme'] ?? null),
            ];
            array_push($covered, ...$bundles);
        }

        // Bundles tiers : leur propre question, après les officielles.
        foreach ($available as $slug => $entry) {
            $custom = $entry['setup_question'] ?? null;
            if (in_array($slug, $covered, true) || !is_array($custom)) {
                continue;
            }
            $texts = is_array($custom['text'] ?? null) ? $custom['text'] : [];
            $text = (string) ($texts[$locale] ?? $texts['en'] ?? reset($texts) ?: '');
            if ($text === '') {
                continue;
            }
            $out[] = [
                'id'      => 'bundle_' . preg_replace('/[^a-z0-9_]/', '_', $slug),
                'text'    => $text,
                'hint'    => (string) ($entry['description'] ?? ''),
                'bundles' => [$slug],
                'icon'    => is_string($custom['icon'] ?? null) ? mb_substr($custom['icon'], 0, 4) : '📦',
                'theme'   => $this->theme($custom['theme'] ?? 'other'),
            ];
        }
        return $out;
    }

    /**
     * Bundles recommandés par les réponses « oui », sans doublon, dans l'ordre des questions.
     *
     * @param list<array{id: string, bundles: list<string>}> $questions
     * @param list<string>                                    $yes       identifiants des questions répondues « oui »
     * @return list<string>
     */
    public function recommend(array $questions, array $yes): array
    {
        $slugs = [];
        foreach ($questions as $question) {
            if (in_array($question['id'], $yes, true)) {
                foreach ($question['bundles'] as $slug) {
                    $slugs[$slug] = true;
                }
            }
        }
        return array_keys($slugs);
    }

    /**
     * Ordre de priorité : bundles des questions d'abord (dans l'ordre des questions), les autres ensuite dans l'ordre reçu.
     * C'est l'ordre dans lequel ils seront installés si le plan n'en permet qu'une partie.
     *
     * @param list<string>                                    $slugs
     * @param list<array{id: string, bundles: list<string>}> $questions
     * @return list<string>
     */
    public function prioritise(array $slugs, array $questions): array
    {
        $rank = [];
        foreach ($questions as $question) {
            foreach ($question['bundles'] as $slug) {
                $rank[$slug] ??= count($rank);
            }
        }
        $indexed = array_values(array_unique($slugs));
        usort($indexed, static fn(string $a, string $b): int => [$rank[$a] ?? PHP_INT_MAX, array_search($a, $slugs, true)] <=> [$rank[$b] ?? PHP_INT_MAX, array_search($b, $slugs, true)]);
        return $indexed;
    }

    /**
     * Pictogramme, thème et bénéfice métier de chaque bundle du catalogue, pour les cartes de sélection.
     *
     * @param list<array{id: string, bundles: list<string>, icon: string, theme: string, hint: string}> $questions
     * @return array<string, array{icon: string, theme: string, hint: string, question: string}> par slug
     */
    public function cards(array $questions): array
    {
        $cards = [];
        foreach ($questions as $question) {
            foreach ($question['bundles'] as $slug) {
                $cards[$slug] ??= ['icon' => $question['icon'], 'theme' => $question['theme'], 'hint' => $question['hint'], 'question' => $question['id']];
            }
        }
        return $cards;
    }

    private function theme(mixed $theme): string
    {
        return is_string($theme) && in_array($theme, self::THEMES, true) ? $theme : 'other';
    }
}
