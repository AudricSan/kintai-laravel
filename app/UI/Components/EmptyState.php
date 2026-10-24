<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class EmptyState implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly string $message;
    private string $icon = '';

    private function __construct(string $message)
    {
        $this->message = $message;
    }

    public static function make(string $message): self
    {
        return new self($message);
    }

    public function icon(string $icon): self       { $this->icon = $icon; return $this; }

    public function render(): string
    {
        $html = '<div' . $this->buildAttributes(['empty-state']) . '>';
        if ($this->icon !== '') {
            $html .= '<div class="empty-state__icon">' . htmlspecialchars($this->icon) . '</div>';
        }
        $html .= htmlspecialchars($this->message);
        $html .= '</div>';
        return $html;
    }
}
