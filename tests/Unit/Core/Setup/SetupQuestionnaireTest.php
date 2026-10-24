<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Setup;

use kintai\Core\Services\BundleRegistry\BundleRegistryEntry;
use kintai\Core\Setup\SetupQuestionnaire;
use PHPUnit\Framework\TestCase;

/** Questionnaire de l'étape Bundles : questions utiles, recommandation, priorité, questions des bundles tiers. */
final class SetupQuestionnaireTest extends TestCase
{
    private const QUESTIONS = [
        ['id' => 'timeclock', 'bundles' => ['timeclock'], 'icon' => '⏱', 'theme' => 'planning'],
        ['id' => 'timeoff', 'bundles' => ['timeoff'], 'icon' => '🏖', 'theme' => 'requests'],
        ['id' => 'exchange', 'bundles' => ['shift-swap', 'shift-claim'], 'icon' => '🔁', 'theme' => 'requests'],
    ];

    private function catalog(string ...$slugs): array
    {
        return array_map(fn(string $slug): array => ['slug' => $slug, 'name' => ucfirst($slug), 'description' => 'Desc ' . $slug], $slugs);
    }

    public function testOnlyQuestionsWithAnAvailableBundleAreAsked(): void
    {
        $questions = (new SetupQuestionnaire(self::QUESTIONS))->questions($this->catalog('timeoff', 'shift-swap'), 'fr');

        $this->assertSame(['timeoff', 'exchange'], array_column($questions, 'id'));
        $this->assertSame(['shift-swap'], $questions[1]['bundles'], 'seuls les bundles du catalogue sont recommandés');
        $this->assertSame('requests', $questions[0]['theme']);
    }

    public function testRecommendationFollowsTheQuestionOrderWithoutDuplicates(): void
    {
        $quest = new SetupQuestionnaire(self::QUESTIONS);
        $questions = $quest->questions($this->catalog('timeclock', 'timeoff', 'shift-swap', 'shift-claim'), 'fr');

        $this->assertSame(['timeoff', 'shift-swap', 'shift-claim'], $quest->recommend($questions, ['exchange', 'timeoff', 'unknown']));
        $this->assertSame([], $quest->recommend($questions, []));
    }

    public function testPriorityPutsQuestionBundlesFirstThenTheOthersInTheirOrder(): void
    {
        $quest = new SetupQuestionnaire(self::QUESTIONS);
        $questions = $quest->questions($this->catalog('timeclock', 'timeoff', 'shift-swap', 'shift-claim', 'zeta', 'alpha'), 'fr');

        $this->assertSame(
            ['timeclock', 'timeoff', 'shift-swap', 'zeta', 'alpha'],
            $quest->prioritise(['zeta', 'shift-swap', 'timeoff', 'timeclock', 'alpha', 'timeoff'], $questions),
            'ordre des questions, puis les bundles hors questionnaire dans l\'ordre reçu, sans doublon',
        );
    }

    public function testAThirdPartyBundleBringsItsOwnQuestionInTheUsersLanguage(): void
    {
        $catalog = $this->catalog('timeoff', 'acme-crm');
        $catalog[1]['setup_question'] = ['text' => ['en' => 'Do you use a CRM?', 'fr' => 'Utilisez-vous un CRM ?'], 'icon' => '🤝', 'theme' => 'bogus'];

        $fr = (new SetupQuestionnaire(self::QUESTIONS))->questions($catalog, 'fr');
        $ja = (new SetupQuestionnaire(self::QUESTIONS))->questions($catalog, 'ja');

        $this->assertSame('Utilisez-vous un CRM ?', $fr[1]['text']);
        $this->assertSame('Do you use a CRM?', $ja[1]['text'], 'repli sur l\'anglais');
        $this->assertSame(['acme-crm'], $fr[1]['bundles']);
        $this->assertSame('other', $fr[1]['theme'], 'thème inconnu = autres');
        $this->assertSame('🤝', $fr[1]['icon']);
    }

    public function testAThirdPartyQuestionWithoutTextOrAlreadyCoveredIsIgnored(): void
    {
        $catalog = $this->catalog('timeoff', 'acme-crm', 'acme-bi');
        $catalog[0]['setup_question'] = ['text' => ['en' => 'Hijack timeoff?']];
        $catalog[1]['setup_question'] = ['text' => []];

        $questions = (new SetupQuestionnaire(self::QUESTIONS))->questions($catalog, 'en');

        $this->assertSame(['timeoff'], array_column($questions, 'id'));
    }

    public function testCardsGiveEachBundleItsIconAndTheme(): void
    {
        $quest = new SetupQuestionnaire(self::QUESTIONS);
        $cards = $quest->cards($quest->questions($this->catalog('timeoff', 'shift-swap'), 'fr'));

        $this->assertSame('🏖', $cards['timeoff']['icon']);
        $this->assertSame('requests', $cards['shift-swap']['theme']);
        $this->assertArrayNotHasKey('timeclock', $cards);
    }

    public function testTheShippedConfigurationOnlyNamesOfficialBundles(): void
    {
        $official = require dirname(__DIR__, 4) . '/config/official-bundles.php';
        $quest = SetupQuestionnaire::fromConfig(dirname(__DIR__, 4) . '/config/setup-questions.php');
        $questions = $quest->questions($this->catalog(...$official), 'fr');

        $this->assertNotEmpty($questions);
        $covered = array_unique(array_merge(...array_column($questions, 'bundles')));
        sort($covered);
        $expected = $official;
        sort($expected);
        $this->assertSame($expected, $covered, 'chaque bundle officiel est recommandé par une question');
    }

    public function testRegistryEntriesCarryTheOptionalQuestionAndRequirements(): void
    {
        $entry = BundleRegistryEntry::fromArray([
            'slug' => 'acme-crm', 'repository_url' => 'https://github.com/acme/kintai-bundle-acme-crm', 'versions' => ['1.0.0'],
            'setup_question' => ['text' => ['en' => 'CRM?']], 'requires_bundles' => ['timeoff', 'Bad Slug', 'messaging'],
        ]);

        $this->assertSame(['text' => ['en' => 'CRM?']], $entry->setupQuestion);
        $this->assertSame(['timeoff', 'messaging'], $entry->requires, 'slugs mal formés ignorés');
        $this->assertNull(BundleRegistryEntry::fromArray(['slug' => 'x', 'repository_url' => 'https://x/y', 'versions' => []])->setupQuestion);
    }
}
