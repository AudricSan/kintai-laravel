<?php

declare(strict_types=1);

namespace App\View\Components\Traits;

trait HasAttributes
{
    private array $extra = [];

    public function attrs(array $attrs): self
    {
        $this->extra = $attrs;
        return $this;
    }

    private function buildAttributes(array $baseClasses): string
    {
        if (isset($this->extra['class'])) {
            $baseClasses[] = $this->extra['class'];
            unset($this->extra['class']);
        }

        $html = ' class="' . htmlspecialchars(implode(' ', $baseClasses)) . '"';
        foreach ($this->extra as $k => $v) {
            $html .= ' ' . $k . '="' . htmlspecialchars((string) $v) . '"';
        }
        return $html;
    }
}
