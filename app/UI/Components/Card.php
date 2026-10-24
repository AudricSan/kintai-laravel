<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Card implements ComponentInterface
{
    use Traits\HasAttributes;

    private string $header = '';
    private string $body = '';
    private string $footer = '';
    private bool $narrow = false;

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public function header(string $header): self  { $this->header = $header; return $this; }
    public function body(string $body): self       { $this->body = $body; return $this; }
    public function footer(string $footer): self   { $this->footer = $footer; return $this; }
    public function narrow(): self                 { $this->narrow = true; return $this; }

    public function render(): string
    {
        $classes = ['card'];
        if ($this->narrow) {
            $classes[] = 'card--narrow';
        }
        $html = '<div' . $this->buildAttributes($classes) . '>';
        if ($this->header !== '') {
            $html .= '<div class="card-header">' . $this->header . '</div>';
        }
        if ($this->body !== '') {
            $html .= '<div class="card-body">' . $this->body . '</div>';
        }
        if ($this->footer !== '') {
            $html .= '<div class="card-footer--muted">' . $this->footer . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
