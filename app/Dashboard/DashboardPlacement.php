<?php

namespace App\Dashboard;

use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;

/**
 * Un widget à sa place dans une disposition résolue.
 */
final class DashboardPlacement
{
    public function __construct(
        public readonly DashboardWidget $widget,
        public readonly WidgetSize $size,
    ) {}
}
