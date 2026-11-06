<?php

namespace App\Domain;

use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Exceptions\StaleVendorException;
use App\Models\StageTransition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * The one write the app supports: moving a vendor to another stage. The rules
 * come from Workflow; this class adds atomicity and concurrency control.
 */
final class ChangeVendorStage
{
    /**
     * $expectedFromStage is the stage the user saw when they made the decision.
     * If another coordinator has moved the vendor since then, the change is
     * refused instead of silently overwriting theirs (the "two people editing
     * the spreadsheet at once" problem).
     *
     * @throws StaleVendorException when the vendor is no longer in $expectedFromStage
     * @throws InvalidTransitionException when the move breaks a workflow rule
     */
    public function handle(
        Vendor $vendor,
        Stage $toStage,
        Stage $expectedFromStage,
        ?string $note,
        User $actor,
    ): StageTransition {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        if ($vendor->stage !== $expectedFromStage) {
            throw new StaleVendorException($vendor);
        }

        $check = Workflow::check($expectedFromStage, $toStage, $note);
        if (! $check->ok) {
            throw new InvalidTransitionException($check->reason);
        }

        $transition = DB::transaction(function () use ($vendor, $toStage, $expectedFromStage, $note, $actor) {
            $now = now();

            // Compare-and-set: only succeeds if the vendor is still in the stage
            // the caller saw. This also covers a change that lands between the
            // check above and this write. The vendor row and its history row
            // are written in one transaction, so they can't drift apart.
            $updated = Vendor::query()
                ->whereKey($vendor->getKey())
                ->where('stage', $expectedFromStage->value)
                ->update(['stage' => $toStage->value, 'stage_entered_at' => $now]);

            if ($updated !== 1) {
                throw new StaleVendorException($vendor->refresh());
            }

            return StageTransition::create([
                'vendor_id' => $vendor->getKey(),
                'from_stage' => $expectedFromStage,
                'to_stage' => $toStage,
                'changed_by' => $actor->getKey(),
                'changed_at' => $now,
                'note' => $note,
            ]);
        });

        $vendor->refresh();

        return $transition;
    }
}
