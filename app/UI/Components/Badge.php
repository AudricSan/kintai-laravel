<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Badge implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly string $label;
    private string $variant = 'neutral';
    private string $size = '';

    private function __construct(string $label)
    {
        $this->label = $label;
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function active(): self    { $this->variant = 'active'; return $this; }
    public function inactive(): self  { $this->variant = 'inactive'; return $this; }
    public function pending(): self   { $this->variant = 'pending'; return $this; }
    public function success(): self   { $this->variant = 'success'; return $this; }
    public function danger(): self    { $this->variant = 'danger'; return $this; }
    public function warning(): self   { $this->variant = 'warning'; return $this; }
    public function info(): self      { $this->variant = 'info'; return $this; }
    public function neutral(): self   { $this->variant = 'neutral'; return $this; }
    public function admin(): self     { $this->variant = 'admin'; return $this; }
    public function staff(): self     { $this->variant = 'staff'; return $this; }
    public function store(): self     { $this->variant = 'store'; return $this; }
    public function primary(): self   { $this->variant = 'primary'; return $this; }
    public function muted(): self     { $this->variant = 'muted'; return $this; }
    public function variant(string $v): self { $this->variant = $v; return $this; }

    public function status(string $status): self
    {
        $this->variant = match ($status) {
            'active', 'approved', 'accepted', 'validated', 'ok', 'success' => 'success',
            'inactive', 'cancelled', 'disabled', 'no'          => 'inactive',
            'pending', 'waiting', 'submitted'                   => 'pending',
            'refused', 'rejected', 'error', 'fail', 'danger'   => 'danger',
            'warning'                                           => 'warning',
            'info', 'manager'                                   => 'info',
            'admin'                                             => 'admin',
            default                                             => 'neutral',
        };
        return $this;
    }

    public function sm(): self        { $this->size = 'sm'; return $this; }
    public function xs(): self        { $this->size = 'xs'; return $this; }

    public function render(): string
    {
        $classes = ['badge', "badge--{$this->variant}"];
        if ($this->size !== '') {
            $classes[] = "badge--{$this->size}";
        }

        return '<span' . $this->buildAttributes($classes) . '>'
            . htmlspecialchars($this->label) . '</span>';
    }
}
