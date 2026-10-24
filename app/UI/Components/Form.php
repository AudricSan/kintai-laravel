<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Form implements ComponentInterface
{
    use Traits\HasAttributes;

    private string $method = 'GET';
    private string $action = '';
    private string $content = '';
    private bool $csrf = false;
    private string $methodSpoof = '';

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public function method(string $method): self   { $this->method = strtoupper($method); return $this; }
    public function action(string $url): self       { $this->action = $url; return $this; }
    public function csrf(): self                    { $this->csrf = true; return $this; }
    public function put(): self                     { $this->methodSpoof = 'PUT'; return $this; }
    public function delete(): self                  { $this->methodSpoof = 'DELETE'; return $this; }

    /**
     * @param string $html Raw HTML content (inputs, buttons, etc.)
     */
    public function content(string $html): self     { $this->content = $html; return $this; }

    public function render(): string
    {
        $html = '<form method="' . htmlspecialchars($this->method) . '"'
            . ($this->action !== '' ? ' action="' . htmlspecialchars($this->action) . '"' : '')
            . $this->buildAttributes(['form']) . '>';

        if ($this->csrf) {
            $html .= csrf_field();
        }

        if ($this->methodSpoof !== '') {
            $html .= '<input type="hidden" name="_method" value="' . htmlspecialchars($this->methodSpoof) . '">';
        }

        $html .= $this->content;
        $html .= '</form>';
        return $html;
    }

    // ── Static input helpers ─────────────────────────────────

    public static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
    }

    public static function text(string $name, string $label, string $value = '', string $placeholder = ''): string
    {
        return self::input('text', $name, $label, $value, $placeholder);
    }

    public static function email(string $name, string $label, string $value = '', string $placeholder = ''): string
    {
        return self::input('email', $name, $label, $value, $placeholder);
    }

    public static function password(string $name, string $label, string $placeholder = ''): string
    {
        return self::input('password', $name, $label, '', $placeholder);
    }

    public static function number(string $name, string $label, string $value = '', array $attrs = []): string
    {
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . $k . '="' . htmlspecialchars((string) $v) . '"';
        }
        return self::input('number', $name, $label, $value, '', $extra);
    }

    public static function date(string $name, string $label, string $value = ''): string
    {
        return self::input('date', $name, $label, $value);
    }

    public static function month(string $name, string $label, string $value = ''): string
    {
        return self::input('month', $name, $label, $value);
    }

    public static function select(string $name, string $label, array $options, string $selected = '', string $placeholder = ''): string
    {
        $html = '<div class="form-group">';
        if ($label !== '') {
            $html .= '<label class="form-label" for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<select id="' . htmlspecialchars($name) . '" name="' . htmlspecialchars($name) . '" class="form-control">';
        if ($placeholder !== '') {
            $html .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
        }
        foreach ($options as $value => $text) {
            $sel = (string) $value === (string) $selected ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars((string) $value) . '"' . $sel . '>' . htmlspecialchars((string) $text) . '</option>';
        }
        $html .= '</select></div>';
        return $html;
    }

    public static function textarea(string $name, string $label, string $value = '', string $placeholder = ''): string
    {
        $html = '<div class="form-group">';
        $html .= '<label class="form-label" for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
        $html .= '<textarea id="' . htmlspecialchars($name) . '" name="' . htmlspecialchars($name) . '" class="form-control"'
            . ($placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '')
            . '>' . htmlspecialchars($value) . '</textarea>';
        $html .= '</div>';
        return $html;
    }

    public static function submit(string $label, string $variant = 'primary'): string
    {
        return Button::make($label)->{$variant}()->submit()->render();
    }

    private static function input(string $type, string $name, string $label, string $value = '', string $placeholder = '', string $extra = ''): string
    {
        $html = '<div class="form-group">';
        if ($label !== '') {
            $html .= '<label class="form-label" for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<input type="' . $type . '" id="' . htmlspecialchars($name) . '" name="' . htmlspecialchars($name) . '"'
            . ' class="form-control"'
            . ($value !== '' ? ' value="' . htmlspecialchars($value) . '"' : '')
            . ($placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '')
            . $extra . '>';
        $html .= '</div>';
        return $html;
    }
}
