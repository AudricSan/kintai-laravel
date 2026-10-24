<?php

declare(strict_types=1);

namespace kintai\Core\Api;

use kintai\Core\Request;

final class Paginator
{
    public static function paginate(array $items, int $page, int $limit): array
    {
        $total  = count($items);
        $offset = ($page - 1) * $limit;
        $data   = array_values(array_slice($items, $offset, $limit));

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
                'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
            ],
        ];
    }

    /** Extrait page et limit depuis la requête. Limites : page >= 1, 1 <= limit <= 200. */
    public static function params(Request $request): array
    {
        $page  = max(1, (int) ($request->query('page', '1') ?? '1'));
        $limit = min(200, max(1, (int) ($request->query('limit', '20') ?? '20')));

        return [$page, $limit];
    }
}
