<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class StoreFormViewTest extends TestCase
{
    public function testAutoPauseFieldsReflectImportSettingsRatherThanStaleJsonColumn(): void
    {
        $view = new ViewRenderer(dirname(__DIR__, 2) . '/app/UI/View');

        $store = [
            'id'   => 1,
            'name' => 'Test Store',
            // Colonne legacy jamais mise à jour par saveImportSettings() : doit être ignorée.
            'excel_import_settings' => json_encode([
                'auto_pause_after_minutes' => 999,
                'auto_pause_minutes'       => 999,
            ]),
        ];

        $importSettings = [
            'auto_pause_after_minutes' => 45,
            'auto_pause_minutes'       => 15,
        ];

        $html = $view->renderPartial('_partials._form-store', [
            'mode'              => 'edit',
            'store'             => $store,
            'deductionSettings' => [],
            'importSettings'    => $importSettings,
            'BASE_URL'          => '',
        ]);

        $this->assertMatchesRegularExpression(
            '/name="auto_pause_after_minutes"[^>]*value="45"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="auto_pause_minutes"[^>]*value="15"/',
            $html
        );
        $this->assertStringNotContainsString('value="999"', $html);
    }

    public function testAutoPauseFieldsFallBackToDefaultsWhenNoImportSettings(): void
    {
        $view = new ViewRenderer(dirname(__DIR__, 2) . '/app/UI/View');

        $html = $view->renderPartial('_partials._form-store', [
            'mode'              => 'create',
            'store'             => [],
            'deductionSettings' => [],
            'BASE_URL'          => '',
        ]);

        $this->assertMatchesRegularExpression(
            '/name="auto_pause_after_minutes"[^>]*value="0"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="auto_pause_minutes"[^>]*value="30"/',
            $html
        );
    }
}
