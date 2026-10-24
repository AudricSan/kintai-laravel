<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Alert implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly string $message;
    private string $variant = 'info';
    private bool $dismissible = false;

    private function __construct(string $message)
    {
        $this->message = $message;
    }

    public static function make(string $message): self
    {
        return new self($message);
    }

    public function success(): self     { $this->variant = 'success'; return $this; }
    public function info(): self        { $this->variant = 'info'; return $this; }
    public function warning(): self     { $this->variant = 'warning'; return $this; }
    public function danger(): self      { $this->variant = 'danger'; return $this; }
    public function error(): self       { $this->variant = 'danger'; return $this; }
    public function dismissible(): self { $this->dismissible = true; return $this; }

    public function render(): string
    {
        $html = '<div' . $this->buildAttributes(['alert', "alert--{$this->variant}"]) . '>'
            . htmlspecialchars($this->message);

        if ($this->dismissible) {
            $html .= '<button type="button" class="alert__close" data-on-click="@removeParent">&times;</button>';
        }

        $html .= '</div>';
        return $html;
    }
}
