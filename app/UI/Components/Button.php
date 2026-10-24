<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Button implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly string $label;
    private string $variant = 'primary';
    private string $size = '';
    private string $href = '';
    private string $type = 'button';
    private bool $disabled = false;

    private function __construct(string $label)
    {
        $this->label = $label;
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function primary(): self    { $this->variant = 'primary'; return $this; }
    public function ghost(): self      { $this->variant = 'ghost'; return $this; }
    public function danger(): self     { $this->variant = 'danger'; return $this; }
    public function success(): self    { $this->variant = 'success'; return $this; }
    public function warning(): self    { $this->variant = 'warning'; return $this; }
    public function secondary(): self  { $this->variant = 'secondary'; return $this; }
    public function outline(): self    { $this->variant = 'outline'; return $this; }

    public function sm(): self         { $this->size = 'sm'; return $this; }
    public function lg(): self         { $this->size = 'lg'; return $this; }
    public function xs(): self         { $this->size = 'xs'; return $this; }
    public function full(): self       { $this->size = 'full'; return $this; }

    public function link(string $url): self    { $this->href = $url; return $this; }
    public function submit(): self             { $this->type = 'submit'; return $this; }
    public function disabled(bool $v = true): self { $this->disabled = $v; return $this; }

    public function render(): string
    {
        $classes = ['btn', "btn--{$this->variant}"];
        if ($this->size !== '') {
            $classes[] = "btn--{$this->size}";
        }

        $allAttrs = $this->extra;
        $allAttrs['class'] = implode(' ', $classes);

        if ($this->disabled) {
            $allAttrs['disabled'] = 'disabled';
        }

        $href = $this->href !== '' ? $this->href : ($allAttrs['href'] ?? '');
        if ($href !== '') {
            $allAttrs['href'] = $href;
            return $this->tag('a', $allAttrs);
        }

        if (!isset($allAttrs['type'])) {
            $allAttrs['type'] = $this->type;
        }
        return $this->tag('button', $allAttrs);
    }

    private function tag(string $tag, array $attrs): string
    {
        $html = "<{$tag}";
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . '="' . htmlspecialchars((string) $v) . '"';
        }
        $html .= '>' . htmlspecialchars($this->label) . "</{$tag}>";
        return $html;
    }
}
