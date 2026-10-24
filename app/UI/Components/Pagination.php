<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Pagination implements ComponentInterface
{
    use Traits\HasAttributes;

    private readonly int $currentPage;
    private readonly int $totalPages;
    private array $queryParams = [];

    private function __construct(int $currentPage, int $totalPages)
    {
        $this->currentPage = max(1, $currentPage);
        $this->totalPages = max(1, $totalPages);
    }

    public static function make(int $currentPage, int $totalPages): self
    {
        return new self($currentPage, $totalPages);
    }

    public function queryParams(array $params): self
    {
        $this->queryParams = $params;
        return $this;
    }

    public function render(): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }

        $html = '<div class="table-footer"><div' . $this->buildAttributes(['pagination']) . '>';

        for ($p = 1; $p <= $this->totalPages; $p++) {
            $params = array_merge($this->queryParams, ['page' => $p]);
            $url = '?' . http_build_query($params);

            if ($p === $this->currentPage) {
                $html .= '<span class="pagination__item pagination__item--active">' . $p . '</span>';
            } else {
                $html .= '<a href="' . htmlspecialchars($url) . '" class="pagination__item">' . $p . '</a>';
            }
        }

        $html .= '</div></div>';
        return $html;
    }
}
