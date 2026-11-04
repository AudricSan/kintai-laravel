<?php

namespace Tests\Support;

use Livewire\Component;
use RuntimeException;

/**
 * Faux widget de bundle dont le rendu échoue.
 */
class ThrowingWidget extends Component
{
    public function render(): never
    {
        throw new RuntimeException('widget exploded');
    }
}
