<?php

namespace App\Domain;

/**
 * Which stage changes are allowed. Pure (no I/O): the stage-change action
 * enforces these rules, and the UI uses allowedTargets() so it only offers
 * moves the server will accept.
 *
 * Rules:
 *  - Forward moves go exactly one stage at a time. Skipping a stage (e.g.
 *    Contract Signed -> KYC Verified) would hide the fact that a step such
 *    as Compliance review never happened.
 *  - Backward moves to any earlier stage are allowed, because they are how
 *    coordinators correct mistakes (a mis-click, or an activation that has
 *    to be rolled back). They must include a reason so the audit trail
 *    explains them.
 *  - Moving to the stage the vendor is already in is rejected.
 */
final class Workflow
{
    public const MAX_NOTE_LENGTH = 500;

    public static function check(Stage $from, Stage $to, ?string $note = null): TransitionCheck
    {
        if ($to === $from) {
            return TransitionCheck::rejected(sprintf('Vendor is already in "%s".', $to->label()));
        }

        if ($to->index() > $from->index()) {
            $next = $from->next();
            if ($to !== $next) {
                return TransitionCheck::rejected(sprintf(
                    'Stages can\'t be skipped. The next stage after "%s" is "%s".',
                    $from->label(),
                    $next?->label(),
                ));
            }

            return TransitionCheck::allowed(TransitionDirection::Forward);
        }

        if ($note === null || trim($note) === '') {
            return TransitionCheck::rejected('Moving a vendor back to an earlier stage requires a reason.');
        }

        return TransitionCheck::allowed(TransitionDirection::Backward);
    }

    /**
     * Every stage a vendor in $from could legally move to: the next stage,
     * then each earlier stage, nearest first. A backward move still needs a reason.
     *
     * @return list<TransitionTarget>
     */
    public static function allowedTargets(Stage $from): array
    {
        $targets = [];

        if ($next = $from->next()) {
            $targets[] = new TransitionTarget($next, TransitionDirection::Forward);
        }

        $stages = Stage::cases();
        for ($i = $from->index() - 1; $i >= 0; $i--) {
            $targets[] = new TransitionTarget($stages[$i], TransitionDirection::Backward);
        }

        return $targets;
    }
}
