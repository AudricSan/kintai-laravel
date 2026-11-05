<?php

namespace Tests\Fixtures\Bundles\DemoBundle;

use Livewire\Component;

/**
 * Widget de tableau de bord du bundle de démonstration.
 */
class DemoWidget extends Component
{
    public function render(): string
    {
        return '<div data-test="demo-widget-content">Demo widget content</div>';
    }
}
