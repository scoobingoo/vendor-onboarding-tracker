<?php

namespace App\Http\Controllers;

use App\Domain\Stage;
use App\Domain\StuckPolicy;
use App\Domain\VendorFilters;
use App\Domain\VendorList;
use App\Domain\VendorSummary;
use App\Domain\Workflow;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tracker page: stage summary, filterable list and, when a vendor is
 * selected, its detail panel with history and the stage-change form.
 */
class VendorController extends Controller
{
    public function index(Request $request): View
    {
        return $this->tracker($request, null);
    }

    public function show(Request $request, Vendor $vendor): View
    {
        return $this->tracker($request, $vendor);
    }

    private function tracker(Request $request, ?Vendor $selected): View
    {
        // One "now" for the whole page, so every row is judged at the same moment.
        $now = now()->toImmutable();
        $filters = VendorFilters::fromQuery($request->query());

        $all = Vendor::query()
            ->with(['coordinator', 'latestTransition.changedBy'])
            ->orderBy('name')
            ->get()
            ->map(fn (Vendor $vendor) => VendorSummary::fromVendor($vendor, $now));

        $pipeline = array_map(fn (Stage $stage) => [
            'stage' => $stage,
            'count' => $all->where('stage', $stage)->count(),
            'stuck' => $all->where('stage', $stage)->where('isStuck', true)->count(),
        ], Stage::cases());

        // Links keep the current filters and the selected vendor.
        $filterUrl = fn (VendorFilters $f) => $selected
            ? route('vendors.show', ['vendor' => $selected] + $f->toQuery())
            : route('vendors.index', $f->toQuery());

        return view('vendors.index', [
            'now' => $now,
            'filters' => $filters,
            'filterUrl' => $filterUrl,
            'pipeline' => $pipeline,
            'stuckCount' => $all->where('isStuck', true)->count(),
            'stuckAfterDays' => StuckPolicy::STUCK_AFTER_DAYS,
            'vendors' => VendorList::filterAndSort($all, $filters, $request->user()->id),
            'selected' => $selected ? $all->firstWhere('id', $selected->id) : null,
            'history' => $selected?->transitions()->with('changedBy')->get(),
            'targets' => $selected ? Workflow::allowedTargets($selected->stage) : [],
        ]);
    }
}
