<?php

declare(strict_types=1);

namespace kintai\UI\Components;

use kintai\UI\Utils\Sort;

final class Table implements ComponentInterface
{
    use Traits\HasAttributes;

    private array $columns = [];
    private array $rows = [];
    private string $emptyMessage = '';
    private string $currentSort = '';
    private array $filters = [];
    private string $sortParam = 'sort';
    private ?string $before = null;
    private ?string $after = null;
    private ?string $beforeBody = null;
    private ?string $afterBody = null;
    private ?\Closure $footerCallback = null;
    private ?\Closure $rowAttrCallback = null;
    private ?\Closure $rowUrlCallback = null;

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public function data(array $data): self
    {
        $this->rows = $data;
        return $this;
    }

    /**
     * Colonne à contenu HTML : la valeur renvoyée par `$render` est insérée TELLE QUELLE
     * dans la cellule (badge, bouton, lien...). C'est donc à l'appelant d'échapper toute
     * donnée saisie par un utilisateur (`htmlspecialchars()`), sous peine de XSS stocké.
     * Pour une simple valeur textuelle, préférer `text()`, qui échappe automatiquement.
     */
    public function column(string $label, callable $render, string $class = ''): self
    {
        $this->columns[] = [
            'type'     => 'simple',
            'label'    => $label,
            'render'   => $render,
            'class'    => $class,
            'key'      => null,
        ];
        return $this;
    }

    /**
     * Colonne de texte : la valeur renvoyée par `$value` est échappée automatiquement
     * (HTML spécial → entités), il n'y a donc rien à penser côté appelant. À utiliser
     * pour toute donnée textuelle ; utiliser `column()` seulement pour du HTML voulu.
     */
    public function text(string $label, callable $value, string $class = ''): self
    {
        return $this->column($label, self::escapingRenderer($value), $class);
    }

    /** Variante triable de `text()` : même échappement automatique. */
    public function sortableText(string $label, string $key, callable $value, string $class = ''): self
    {
        return $this->sortable($label, $key, self::escapingRenderer($value), $class);
    }

    private static function escapingRenderer(callable $value): \Closure
    {
        return static fn($row, $i = null): string => htmlspecialchars(
            (string) $value($row, $i),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /** Colonne triable à contenu HTML : mêmes règles d'échappement que `column()`. */
    public function sortable(string $label, string $key, callable $render, string $class = ''): self
    {
        $this->columns[] = [
            'type'     => 'sortable',
            'label'    => $label,
            'key'      => $key,
            'render'   => $render,
            'class'    => $class,
        ];
        return $this;
    }

    public function currentSort(string $sort): self
    {
        $this->currentSort = $sort;
        return $this;
    }

    public function filters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * Nom du paramètre de requête utilisé pour le tri (par défaut "sort").
     * Utile quand plusieurs tableaux triables cohabitent sur la même page.
     */
    public function sortParam(string $name): self
    {
        $this->sortParam = $name;
        return $this;
    }

    public function emptyMessage(string $msg): self
    {
        $this->emptyMessage = $msg;
        return $this;
    }

    public function before(string $html): self
    {
        $this->before = $html;
        return $this;
    }

    public function after(string $html): self
    {
        $this->after = $html;
        return $this;
    }

    public function beforeBody(string $html): self
    {
        $this->beforeBody = $html;
        return $this;
    }

    public function afterBody(string $html): self
    {
        $this->afterBody = $html;
        return $this;
    }

    /**
     * Set a footer callback that receives the full data array.
     * Callback: fn(array $rows, array $columns) => string (raw HTML for <tfoot><tr>...</tr></tfoot>)
     */
    public function footer(callable $callback): self
    {
        $this->footerCallback = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return $this;
    }

    /**
     * Set a row attributes callback.
     * Callback: fn(array $row, int $index) => array (e.g. ['class' => 'tr-total', 'data-id' => '123'])
     */
    public function rowAttrs(callable $callback): self
    {
        $this->rowAttrCallback = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return $this;
    }

    /**
     * Set a callback that returns a URL for each row, making the row clickable.
     * Callback: fn(array $row, int $index) => string (URL)
     */
    public function rowUrl(callable $callback): self
    {
        $this->rowUrlCallback = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return $this;
    }

    public function render(): string
    {
        if (empty($this->rows) && $this->emptyMessage !== '') {
            return EmptyState::make($this->emptyMessage)->render();
        }

        $html = '';
        if ($this->before !== null) {
            $html .= $this->before;
        }

        $html .= '<div class="table-wrap">';
        $html .= '<table' . $this->buildAttributes(['data-table']) . ' data-mob-stack>';
        $html .= '<thead><tr>';

        foreach ($this->columns as $col) {
            if ($col['type'] === 'sortable') {
                $th = Sort::th($col['label'], $col['key'], $this->currentSort, $this->filters, $this->sortParam);
                if ($col['class'] !== '') {
                    $th = str_replace('<th', '<th class="' . htmlspecialchars($col['class']) . '"', $th);
                }
                $html .= $th;
            } else {
                $classAttr = $col['class'] !== '' ? ' class="' . htmlspecialchars($col['class']) . '"' : '';
                $html .= '<th' . $classAttr . '>' . htmlspecialchars($col['label']) . '</th>';
            }
        }

        $html .= '</tr></thead><tbody>';

        if ($this->beforeBody !== null) {
            $html .= $this->beforeBody;
        }

        foreach ($this->rows as $i => $row) {
            $rowAttrs = '';
            if ($this->rowAttrCallback !== null) {
                $attrs = ($this->rowAttrCallback)($row, $i);
                foreach ($attrs as $k => $v) {
                    $rowAttrs .= ' ' . $k . '="' . htmlspecialchars((string) $v) . '"';
                }
            }
            if ($this->rowUrlCallback !== null) {
                $url = ($this->rowUrlCallback)($row, $i);
                if ($url !== '') {
                    $rowAttrs .= ' data-href="' . htmlspecialchars($url) . '"';
                    if (strpos($rowAttrs, 'class=') === false) {
                        $rowAttrs .= ' class="tr--clickable"';
                    } else {
                        $rowAttrs = preg_replace('/class="([^"]*)"/', 'class="$1 tr--clickable"', $rowAttrs);
                    }
                }
            }
            $html .= '<tr' . $rowAttrs . '>';
            foreach ($this->columns as $col) {
                $classAttr = $col['class'] !== '' ? ' class="' . htmlspecialchars($col['class']) . '"' : '';
                $html .= '<td' . $classAttr . ' data-label="' . htmlspecialchars($col['label']) . '">';
                $html .= ($col['render'])($row, $i);
                $html .= '</td>';
            }
            $html .= '</tr>';
        }

        if ($this->afterBody !== null) {
            $html .= $this->afterBody;
        }

        $html .= '</tbody>';

        if ($this->footerCallback !== null) {
            $html .= '<tfoot>';
            $html .= ($this->footerCallback)($this->rows, $this->columns);
            $html .= '</tfoot>';
        }

        $html .= '</table></div>';

        if ($this->after !== null) {
            $html .= $this->after;
        }

        return $html;
    }
}
