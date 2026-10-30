<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ActivityEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActivityController
{
    public function __construct(
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'level'         => $request->query('level'),
            'channel'       => $request->query('channel'),
            'action'        => $request->query('action'),
            'resource_type' => $request->query('resource_type'),
            'user_id'       => (int) ($request->query('user_id') ?? 0) ?: null,
            'store_id'      => (int) ($request->query('store_id') ?? 0) ?: null,
            'from'          => $request->query('from'),
            'to'            => $request->query('to'),
            'query'         => $request->query('query'),
        ];
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        $page    = max(1, (int) ($request->query('page', '1') ?? '1'));
        $perPage = min(500, max(1, (int) ($request->query('per_page', '50') ?? '50')));

        $rows  = ActivityEntry::findAll($page, $perPage, $filters);
        $total = ActivityEntry::countAll($filters);

        return response()->json([
            'data'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }
}
