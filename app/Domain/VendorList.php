<?php

namespace App\Domain;

use Illuminate\Support\Collection;

final class VendorList
{
    /**
     * The order a coordinator should work through the list: stuck vendors
     * first (longest-waiting at the top), then other in-progress vendors, then
     * the ones that have finished onboarding.
     */
    public static function compareByAttention(VendorSummary $a, VendorSummary $b): int
    {
        if ($a->isStuck !== $b->isStuck) {
            return $a->isStuck ? -1 : 1;
        }

        $aDone = $a->stage->isTerminal();
        $bDone = $b->stage->isTerminal();
        if ($aDone !== $bDone) {
            return $aDone ? 1 : -1;
        }

        return [$b->daysInStage, strtolower($a->name)] <=> [$a->daysInStage, strtolower($b->name)];
    }

    /**
     * @param  iterable<VendorSummary>  $vendors
     * @return Collection<int, VendorSummary>
     */
    public static function filterAndSort(iterable $vendors, VendorFilters $filters, int $currentUserId): Collection
    {
        $search = mb_strtolower($filters->search);

        return collect($vendors)
            ->filter(fn (VendorSummary $v) => $filters->stage === null || $v->stage === $filters->stage)
            ->filter(fn (VendorSummary $v) => ! $filters->stuckOnly || $v->isStuck)
            ->filter(fn (VendorSummary $v) => ! $filters->mineOnly || $v->coordinatorId === $currentUserId)
            ->filter(fn (VendorSummary $v) => $search === ''
                || str_contains(mb_strtolower($v->name), $search)
                || str_contains(mb_strtolower($v->region), $search))
            ->sort(self::compareByAttention(...))
            ->values();
    }
}
