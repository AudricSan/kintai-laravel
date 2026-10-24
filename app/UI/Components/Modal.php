<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Modal implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly string $id;
    private string $title = '';
    private string $body = '';
    private string $footer = '';
    private bool $wide = false;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function make(string $id): self
    {
        return new self($id);
    }

    public function title(string $title): self   { $this->title = $title; return $this; }
    public function body(string $body): self      { $this->body = $body; return $this; }
    public function footer(string $footer): self  { $this->footer = $footer; return $this; }
    public function wide(): self                  { $this->wide = true; return $this; }

    public function render(): string
    {
        $dialogClass = 'modal__dialog';
        if ($this->wide) {
            $dialogClass .= ' modal__dialog--wide';
        }

        $html = '<div id="' . htmlspecialchars($this->id) . '"' . $this->buildAttributes(['modal']) . '>';
        $html .= '<div class="modal__backdrop" data-on-click="closeModal" data-args="' . htmlspecialchars(json_encode([$this->id]), ENT_QUOTES) . '"></div>';
        $html .= '<div class="' . $dialogClass . '">';

        if ($this->title !== '') {
            $html .= '<div class="modal__header">';
            $html .= '<h3 class="modal__title">' . $this->title . '</h3>';
            $html .= '<button class="modal__close" data-on-click="closeModal" data-args="' . htmlspecialchars(json_encode([$this->id]), ENT_QUOTES) . '">&times;</button>';
            $html .= '</div>';
        }

        if ($this->body !== '') {
            $html .= '<div class="modal__body">' . $this->body . '</div>';
        }

        if ($this->footer !== '') {
            $html .= '<div class="modal__footer">' . $this->footer . '</div>';
        }

        $html .= '</div></div>';

        // Inline JS for modal open/close if not already defined
        $html .= '<script nonce="' . csp_nonce() . '">
if (typeof window.openModal === "undefined") {
    window.openModal = function(id) { document.getElementById(id).hidden = false; };
    window.closeModal = function(id) { document.getElementById(id).hidden = true; };
}
</script>';

        return $html;
    }
}
