<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

final class StoreFormViewTest extends TestCase
{
    public function testAutoPauseFieldsReflectImportSettingsRatherThanStaleJsonColumn(): void
    {
        $view = kintai_test_views(dirname(__DIR__, 2) . '/resources/views');

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

        $html = $view->make('_partials._form-store', [
            'mode'              => 'edit',
            'store'             => $store,
            'deductionSettings' => [],
            'importSettings'    => $importSettings,
            'BASE_URL'          => '',
        ])->render();

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
        $view = kintai_test_views(dirname(__DIR__, 2) . '/resources/views');

        $html = $view->make('_partials._form-store', [
            'mode'              => 'create',
            'store'             => [],
            'deductionSettings' => [],
            'BASE_URL'          => '',
        ])->render();

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
