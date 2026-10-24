<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Tabs implements ComponentInterface
{
    use Traits\HasAttributes;

    private array $items = [];
    private string $active = '';

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param array<string, string> $items  Map of url => label
     */
    public function items(array $items): self
    {
        $this->items = $items;
        return $this;
    }

    /**
     * @param string $active  The URL of the active tab.
     */
    public function active(string $active): self
    {
        $this->active = $active;
        return $this;
    }

    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $html = '<div' . $this->buildAttributes(['tabs']) . '>';

        foreach ($this->items as $url => $label) {
            $isActive = ($url === $this->active);
            $class = 'tabs__item';
            if ($isActive) {
                $class .= ' tabs__item--active';
            }

            if ($isActive) {
                $html .= '<span class="' . $class . '">' . htmlspecialchars($label) . '</span>';
            } else {
                $html .= '<a href="' . htmlspecialchars($url) . '" class="' . $class . '">' . htmlspecialchars($label) . '</a>';
            }
        }

        $html .= '</div>';
        return $html;
    }
}
