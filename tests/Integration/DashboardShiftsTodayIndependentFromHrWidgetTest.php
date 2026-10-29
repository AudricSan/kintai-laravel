<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Container\Container;
use kintai\UI\Controller\Web\HomeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Régression : le bloc "timeoff_by_type" mort, commenté avec
 * <!-- <?php if (...): ?> ... <?php endif; ?> -->, laissait le commentaire HTML
 * ouvert quand la condition PHP interne était fausse (le "-->" de fermeture
 * n'était alors jamais émis, lui aussi à l'intérieur du if). Le commentaire
 * avalait alors les </div> fermant la carte "hr_absenteeism" et la grille
 * .dash-two-col, jusqu'au prochain "-->" du flux — celui du commentaire
 * "<!-- Shifts du jour -->" — rattachant silencieusement toute la carte
 * "Shifts today" comme enfant de la carte "HR & Absenteeism".
 */
final class DashboardShiftsTodayIndependentFromHrWidgetTest extends TestCase
{
    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->view = new ViewRenderer(dirname(__DIR__, 2) . '/app/UI/View');

        $router = kintai_test_registrar();
        $router->get('/admin/dashboard/widgets', [\stdClass::class, 'x'], name: 'admin.dashboard.widgets');
        $router->post('/admin/dashboard/layout', [\stdClass::class, 'x'], name: 'admin.dashboard.layout');
        $router->get('/admin/shifts', [\stdClass::class, 'x'], name: 'admin.shifts');
    }

    public function testShiftsTodayCardIsNotNestedInsideHrAbsenteeismCard(): void
    {
        $html = $this->view->renderPartial('dashboard.index', [
            'BASE_URL'           => '',
            'stats'              => [],
            'shifts_today'       => [
                ['id' => 1, 'store_id' => 1, 'store_name' => 'Store 1', 'user_name' => 'Jean Dupont', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60],
            ],
            'pending_timeoff'    => [],
            'pending_swaps'      => [],
            'pending_claims'     => [],
            'users_map'          => [],
            'active_clocks_now'  => [],
            'store_stats_rows'   => [],
            'store_stats_period' => 30,
            'dashboard_alerts'   => null,
            'financial_overview' => null,
            // hr_absenteeism activé avec timeoff_taken = 0 : c'est exactement le
            // scénario ("aucun congé pris") qui déclenchait le bug avant le fix.
            'hr_stats'           => ['abs_rate' => 0.0, 'timeoff_taken' => 0, 'late_count' => 0, 'overtime_weeks' => 0, 'timeoff_by_type' => []],
            'enabled_widgets'    => array_flip(['hr_absenteeism', 'shifts_today']),
            'all_widgets'        => HomeController::ADMIN_WIDGETS,
        ]);

        // libxml's HTML parser is more forgiving than a real browser about an
        // unterminated "<!--" (it doesn't swallow everything up to the next
        // "-->" the way browsers do per the HTML5 spec), so it wouldn't
        // reproduce the bug this test guards against. Simulate the browser's
        // comment-parsing rule ourselves before handing the markup to DOMDocument.
        $withoutComments = $this->stripHtmlCommentsLikeBrowser($html);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $withoutComments . '</body>');
        libxml_use_internal_errors(false);
        $xpath = new DOMXPath($dom);

        // Le libellé "widget_hr_absenteeism" apparaît deux fois : dans la case
        // à cocher du panneau "Personnaliser" (avant, dans le DOM) et dans le
        // vrai header de la carte (après) — on veut ce dernier.
        $hrHeader = $xpath->query("//span[contains(text(), 'widget_hr_absenteeism')]");
        $this->assertGreaterThan(0, $hrHeader->length, 'Carte HR & Absentéisme introuvable dans le rendu.');

        $shiftsHeader = $xpath->query("//span[contains(text(), 'shifts_of_day')]");
        $this->assertGreaterThan(0, $shiftsHeader->length, 'Carte Shifts du jour introuvable dans le rendu.');

        // La carte "Shifts du jour" (ancêtre .card le plus proche du header) ne
        // doit pas avoir la carte "HR & Absentéisme" comme ancêtre.
        $shiftsCard = $this->closestCard($shiftsHeader->item(0));
        $hrCard     = $this->closestCard($hrHeader->item($hrHeader->length - 1));

        $this->assertNotNull($shiftsCard);
        $this->assertNotNull($hrCard);
        $this->assertFalse(
            $this->isDescendantOf($shiftsCard, $hrCard),
            'La carte "Shifts du jour" est imbriquée dans la carte "HR & Absentéisme" au lieu d\'en être indépendante.'
        );
    }

    /**
     * Mimique la règle HTML5 de parsing des commentaires (pas d'imbrication :
     * "<!--" se termine au tout premier "-->" rencontré ensuite ; s'il n'y en
     * a pas, tout le reste du document est avalé) — plus stricte que le
     * parseur HTML de libxml utilisé par DOMDocument, qui ne reproduit pas ce
     * comportement.
     */
    private function stripHtmlCommentsLikeBrowser(string $html): string
    {
        $result = '';
        $offset = 0;
        while (($start = strpos($html, '<!--', $offset)) !== false) {
            $result .= substr($html, $offset, $start - $offset);
            $end = strpos($html, '-->', $start + 4);
            if ($end === false) {
                return $result;
            }
            $offset = $end + 3;
        }
        return $result . substr($html, $offset);
    }

    private function closestCard(\DOMNode $node): ?\DOMElement
    {
        $current = $node->parentNode;
        while ($current instanceof \DOMElement) {
            $class = $current->getAttribute('class');
            if ($class !== '' && in_array('card', explode(' ', $class), true)) {
                return $current;
            }
            $current = $current->parentNode;
        }
        return null;
    }

    private function isDescendantOf(\DOMNode $node, \DOMNode $ancestor): bool
    {
        $current = $node->parentNode;
        while ($current !== null) {
            if ($current === $ancestor) {
                return true;
            }
            $current = $current->parentNode;
        }
        return false;
    }
}
