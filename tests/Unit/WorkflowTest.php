<?php

namespace Tests\Unit;

use App\Domain\Stage;
use App\Domain\TransitionDirection;
use App\Domain\TransitionTarget;
use App\Domain\Workflow;
use PHPUnit\Framework\TestCase;

/**
 * The transition rules are the core business logic, so they get exhaustive,
 * table-style coverage.
 */
class WorkflowTest extends TestCase
{
    public function test_allows_moving_forward_exactly_one_stage(): void
    {
        $stages = Stage::cases();
        for ($i = 0; $i < count($stages) - 1; $i++) {
            $check = Workflow::check($stages[$i], $stages[$i + 1]);
            $this->assertTrue($check->ok);
            $this->assertSame(TransitionDirection::Forward, $check->direction);
        }
    }

    public function test_rejects_skipping_stages(): void
    {
        $check = Workflow::check(Stage::ContractSigned, Stage::KycVerified);
        $this->assertFalse($check->ok);
        $this->assertStringContainsString('KYC Docs Received', $check->reason);

        $this->assertFalse(Workflow::check(Stage::ContractSent, Stage::Active)->ok);
    }

    public function test_rejects_moving_to_the_current_stage(): void
    {
        foreach (Stage::cases() as $stage) {
            $this->assertFalse(Workflow::check($stage, $stage, 'some note')->ok);
        }
    }

    public function test_requires_a_non_blank_reason_to_move_backwards(): void
    {
        $this->assertFalse(Workflow::check(Stage::KycVerified, Stage::KycDocsReceived)->ok);
        $this->assertFalse(Workflow::check(Stage::KycVerified, Stage::KycDocsReceived, '   ')->ok);

        $check = Workflow::check(Stage::KycVerified, Stage::KycDocsReceived, 'Compliance rejected');
        $this->assertTrue($check->ok);
        $this->assertSame(TransitionDirection::Backward, $check->direction);
    }

    public function test_allows_correcting_by_more_than_one_stage_backwards(): void
    {
        $this->assertTrue(Workflow::check(Stage::Active, Stage::ContractSent, 'Wrong vendor activated')->ok);
    }

    public function test_offers_only_the_next_stage_forward_plus_every_earlier_stage(): void
    {
        $this->assertEquals([
            new TransitionTarget(Stage::KycVerified, TransitionDirection::Forward),
            new TransitionTarget(Stage::ContractSigned, TransitionDirection::Backward),
            new TransitionTarget(Stage::ContractSent, TransitionDirection::Backward),
        ], Workflow::allowedTargets(Stage::KycDocsReceived));
    }

    public function test_offers_nothing_forward_from_active_and_nothing_backward_from_contract_sent(): void
    {
        foreach (Workflow::allowedTargets(Stage::Active) as $target) {
            $this->assertTrue($target->isBackward());
        }

        $this->assertEquals(
            [new TransitionTarget(Stage::ContractSigned, TransitionDirection::Forward)],
            Workflow::allowedTargets(Stage::ContractSent),
        );
    }

    public function test_allowed_targets_agree_with_check_so_the_ui_never_offers_a_rejected_move(): void
    {
        foreach (Stage::cases() as $from) {
            foreach (Workflow::allowedTargets($from) as $target) {
                $this->assertTrue(
                    Workflow::check($from, $target->stage, 'reason')->ok,
                    "{$from->value} -> {$target->stage->value} is offered but rejected",
                );
            }
        }
    }

    public function test_accepts_only_known_stage_codes(): void
    {
        $this->assertSame(Stage::Active, Stage::tryFrom('ACTIVE'));
        $this->assertNull(Stage::tryFrom('Active'));
        $this->assertNull(Stage::tryFrom(''));
    }
}
