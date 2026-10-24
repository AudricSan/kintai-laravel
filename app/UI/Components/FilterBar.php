<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class FilterBar implements ComponentInterface
{
    use Traits\HasAttributes;

    private array $fields = [];
    private string $action = '';
    private array $hidden = [];
    private bool $showReset = true;

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public function action(string $url): self       { $this->action = $url; return $this; }

    public function select(string $name, string $label, array $options, string $selected = '', string $placeholder = ''): self
    {
        $this->fields[] = [
            'type'        => 'select',
            'name'        => $name,
            'label'       => $label,
            'options'     => $options,
            'selected'    => $selected,
            'placeholder' => $placeholder,
        ];
        return $this;
    }

    public function search(string $name, string $label, string $value = '', string $placeholder = ''): self
    {
        $this->fields[] = [
            'type'        => 'search',
            'name'        => $name,
            'label'       => $label,
            'value'       => $value,
            'placeholder' => $placeholder,
        ];
        return $this;
    }

    public function date(string $name, string $label, string $value = ''): self
    {
        $this->fields[] = [
            'type'  => 'date',
            'name'  => $name,
            'label' => $label,
            'value' => $value,
        ];
        return $this;
    }

    public function month(string $name, string $label, string $value = ''): self
    {
        $this->fields[] = [
            'type'  => 'month',
            'name'  => $name,
            'label' => $label,
            'value' => $value,
        ];
        return $this;
    }

    public function hidden(array $params): self
    {
        $this->hidden = $params;
        return $this;
    }

    public function showReset(bool $v = true): self
    {
        $this->showReset = $v;
        return $this;
    }

    public function render(): string
    {
        if (empty($this->fields)) {
            return '';
        }

        $html = '<div' . $this->buildAttributes(['card', 'card--filters', 'mb-sm']) . '>';
        $html .= '<form method="GET" action="' . htmlspecialchars($this->action) . '" class="shifts-filters">';
        $html .= '<div class="shifts-filters__row">';

        foreach ($this->fields as $field) {
            $html .= '<div class="shifts-filters__group">';
            $html .= '<label class="shifts-filters__label" for="ff-' . htmlspecialchars($field['name']) . '">'
                . htmlspecialchars($field['label']) . '</label>';

            switch ($field['type']) {
                case 'select':
                    $html .= '<select id="ff-' . htmlspecialchars($field['name']) . '"'
                        . ' name="' . htmlspecialchars($field['name']) . '"'
                        . ' class="form-control form-control-sm"'
                        . ' data-submit-on-change>';
                    if ($field['placeholder'] !== '') {
                        $html .= '<option value="">' . htmlspecialchars($field['placeholder']) . '</option>';
                    }
                    foreach ($field['options'] as $val => $text) {
                        $sel = (string) $val === (string) $field['selected'] ? ' selected' : '';
                        $html .= '<option value="' . htmlspecialchars((string) $val) . '"' . $sel . '>'
                            . htmlspecialchars((string) $text) . '</option>';
                    }
                    $html .= '</select>';
                    break;

                case 'search':
                    $html .= '<input type="text" id="ff-' . htmlspecialchars($field['name']) . '"'
                        . ' name="' . htmlspecialchars($field['name']) . '"'
                        . ' value="' . htmlspecialchars($field['value']) . '"'
                        . ($field['placeholder'] !== '' ? ' placeholder="' . htmlspecialchars($field['placeholder']) . '"' : '')
                        . ' class="form-control form-control-sm">';
                    break;

                case 'date':
                    $html .= '<input type="date" id="ff-' . htmlspecialchars($field['name']) . '"'
                        . ' name="' . htmlspecialchars($field['name']) . '"'
                        . ' value="' . htmlspecialchars($field['value']) . '"'
                        . ' class="form-control form-control-sm"'
                        . ' data-submit-on-change>';
                    break;

                case 'month':
                    $html .= '<input type="month" id="ff-' . htmlspecialchars($field['name']) . '"'
                        . ' name="' . htmlspecialchars($field['name']) . '"'
                        . ' value="' . htmlspecialchars($field['value']) . '"'
                        . ' class="form-control form-control-sm"'
                        . ' data-submit-on-change>';
                    break;
            }

            $html .= '</div>'; // shifts-filters__group
        }

        // Actions
        $html .= '<div class="shifts-filters__actions">';
        if ($this->showReset) {
            $html .= '<a href="' . htmlspecialchars($this->action ?: '?') . '" class="btn btn--ghost btn--sm">Réinitialiser</a>';
        }
        $html .= '</div>';

        // Hidden fields
        foreach ($this->hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $value) . '">';
        }

        $html .= '</div></form></div>';
        return $html;
    }
}
