<?php

namespace App\Http\Controllers;

use App\Authorization\Authorizer;
use App\Planning\TimelineBuilder;
use App\Planning\TimelineViewport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Version imprimable de la frise : document autonome, sans navigation (mise en page `@media print`).
 */
class TimelinePrintController extends Controller
{
    public function __invoke(Request $request, TimelineBuilder $builder, Authorizer $authorizer): View
    {
        $user = $request->user();
        $viewport = TimelineViewport::resolve(
            $user,
            $request->query('store_id') === null ? null : (string) $request->query('store_id'),
            $request->query('start') === null ? null : (string) $request->query('start'),
            $request->query('view') === null ? null : (string) $request->query('view'),
        );

        $store = $viewport->store;
        abort_if($store === null, 404);

        $withCost = $authorizer->allows($user, 'payroll.view', $store);

        return view('timeline-print', [
            'store' => $store,
            'viewport' => $viewport,
            'days' => $builder->build($store, $viewport->firstDay, $viewport->numDays, $withCost),
            'withCost' => $withCost,
        ]);
    }
}
