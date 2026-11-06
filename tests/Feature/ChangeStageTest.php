<?php

namespace Tests\Feature;

use App\Domain\ChangeVendorStage;
use App\Domain\Exceptions\StaleVendorException;
use App\Domain\Stage;
use App\Domain\StuckPolicy;
use App\Models\StageTransition;
use App\Models\Vendor;
use Illuminate\Testing\TestResponse;

/**
 * Every stage change is recorded with who/when/from/to, and invalid or stale
 * changes leave no trace.
 */
class ChangeStageTest extends DemoDataTestCase
{
    /** @param array<string, mixed> $body */
    private function changeStage(string $username, Vendor $vendor, array $body): TestResponse
    {
        return $this->actingAs($this->user($username))
            ->post(route('vendors.transitions.store', $vendor), $body);
    }

    /** @return list<array<string, mixed>> */
    private function historyOf(Vendor $vendor): array
    {
        return $vendor->transitions()->get()->toArray();
    }

    public function test_records_who_when_previous_stage_and_new_stage(): void
    {
        $vendor = $this->vendor('Company F'); // Contract Sent
        $this->travelTo($now = $this->start->addMinute());

        $this->changeStage('mai', $vendor, [
            'to_stage' => 'CONTRACT_SIGNED',
            'expected_from_stage' => 'CONTRACT_SENT',
            'note' => '  Signed via email  ',
        ])
            ->assertRedirect(route('vendors.show', $vendor))
            ->assertSessionHas('success', 'Moved to "Contract Signed".');

        $vendor->refresh();
        $this->assertSame(Stage::ContractSigned, $vendor->stage);
        $this->assertTrue($vendor->stage_entered_at->equalTo($now));

        $latest = $vendor->transitions()->first();
        $this->assertSame(Stage::ContractSent, $latest->from_stage);
        $this->assertSame(Stage::ContractSigned, $latest->to_stage);
        $this->assertTrue($latest->changed_at->equalTo($now));
        $this->assertSame('mai', $latest->changedBy->username);
        $this->assertSame('Signed via email', $latest->note);
    }

    public function test_keeps_the_list_filters_when_returning_to_the_vendor(): void
    {
        $vendor = $this->vendor('Company C');

        $this->actingAs($this->user('linh'))
            ->post(route('vendors.transitions.store', ['vendor' => $vendor, 'mine' => '1', 'q' => 'han']), [
                'to_stage' => 'KYC_DOCS_RECEIVED',
                'expected_from_stage' => 'CONTRACT_SIGNED',
            ])
            ->assertRedirect(route('vendors.show', ['vendor' => $vendor, 'mine' => '1', 'q' => 'han']));
    }

    public function test_resets_the_stuck_clock_when_a_stuck_vendor_moves_on(): void
    {
        $e = $this->vendor('Company E');
        $this->assertTrue(StuckPolicy::isStuck($e->stage, $e->stage_entered_at, now()));

        $this->changeStage('huy', $e, ['to_stage' => 'KYC_VERIFIED', 'expected_from_stage' => 'KYC_DOCS_RECEIVED'])
            ->assertSessionHas('success');

        $e->refresh();
        $this->assertFalse(StuckPolicy::isStuck($e->stage, $e->stage_entered_at, now()));
        $this->assertSame(0, StuckPolicy::daysInStage($e->stage_entered_at, now()));
    }

    public function test_rejects_a_stale_update_when_another_coordinator_changed_the_vendor_first(): void
    {
        $c = $this->vendor('Company C'); // both coordinators loaded it at Contract Signed

        $this->changeStage('huy', $c, ['to_stage' => 'KYC_DOCS_RECEIVED', 'expected_from_stage' => 'CONTRACT_SIGNED'])
            ->assertSessionHas('success');
        $historyAfterHuy = $this->historyOf($c);

        // Linh, still looking at the old page, tries to move it "back" a step.
        $this->changeStage('linh', $c, [
            'to_stage' => 'CONTRACT_SENT',
            'expected_from_stage' => 'CONTRACT_SIGNED',
            'note' => 'Contract needs re-signing',
        ])
            ->assertRedirect(route('vendors.show', $c))
            ->assertSessionHas('warning', 'Not saved: Huy Tran already moved this vendor to "KYC Docs Received". The view has been refreshed. Please review before trying again.')
            ->assertSessionMissing('success');

        $this->assertSame(Stage::KycDocsReceived, $c->refresh()->stage);
        $this->assertSame($historyAfterHuy, $this->historyOf($c));
    }

    public function test_detects_a_concurrent_change_that_lands_between_reading_and_writing(): void
    {
        $loadedByLinh = $this->vendor('Company C');

        // Huy's change commits after Linh's request loaded the vendor.
        Vendor::whereKey($loadedByLinh->id)->update(['stage' => 'KYC_DOCS_RECEIVED']);

        try {
            app(ChangeVendorStage::class)->handle(
                $loadedByLinh, Stage::KycDocsReceived, Stage::ContractSigned, null, $this->user('linh'),
            );
            $this->fail('Expected a StaleVendorException.');
        } catch (StaleVendorException $e) {
            $this->assertSame(Stage::KycDocsReceived, $e->current->stage);
        }

        $this->assertSame(2, StageTransition::where('vendor_id', $loadedByLinh->id)->count());
    }

    public function test_rejects_skipping_a_stage_and_leaves_no_trace(): void
    {
        $c = $this->vendor('Company C');
        $before = $this->historyOf($c);

        $this->changeStage('linh', $c, ['to_stage' => 'ACTIVE', 'expected_from_stage' => 'CONTRACT_SIGNED'])
            ->assertRedirect(route('vendors.show', $c))
            ->assertSessionHasErrors(['transition' => 'Stages can\'t be skipped. The next stage after "Contract Signed" is "KYC Docs Received".']);

        $this->assertSame($before, $this->historyOf($c));
        $this->assertSame(Stage::ContractSigned, $c->refresh()->stage);
    }

    public function test_allows_a_backward_correction_only_with_a_reason(): void
    {
        $d = $this->vendor('Company D'); // KYC Verified

        $this->changeStage('mai', $d, [
            'to_stage' => 'KYC_DOCS_RECEIVED',
            'expected_from_stage' => 'KYC_VERIFIED',
            'note' => '   ',
        ])->assertSessionHasErrors(['transition' => 'Moving a vendor back to an earlier stage requires a reason.']);
        $this->assertSame(Stage::KycVerified, $d->refresh()->stage);

        $this->changeStage('mai', $d, [
            'to_stage' => 'KYC_DOCS_RECEIVED',
            'expected_from_stage' => 'KYC_VERIFIED',
            'note' => 'ID document was blurry, Compliance asked for a new scan',
        ])->assertSessionHas('success');

        $latest = $d->transitions()->first();
        $this->assertSame(Stage::KycVerified, $latest->from_stage);
        $this->assertSame(Stage::KycDocsReceived, $latest->to_stage);
        $this->assertSame('ID document was blurry, Compliance asked for a new scan', $latest->note);
        $this->assertTrue($latest->isBackward());
    }

    public function test_validates_the_request_body(): void
    {
        $c = $this->vendor('Company C');
        $before = $this->historyOf($c);

        $this->changeStage('linh', $c, ['to_stage' => 'Done', 'expected_from_stage' => 'CONTRACT_SIGNED'])
            ->assertRedirect(route('vendors.show', $c))
            ->assertSessionHasErrors('to_stage');
        $this->changeStage('linh', $c, ['to_stage' => 'KYC_DOCS_RECEIVED'])
            ->assertSessionHasErrors('expected_from_stage');
        $this->changeStage('linh', $c, [
            'to_stage' => 'KYC_DOCS_RECEIVED',
            'expected_from_stage' => 'CONTRACT_SIGNED',
            'note' => str_repeat('x', 501),
        ])->assertSessionHasErrors('note');

        $this->assertSame($before, $this->historyOf($c));

        $this->actingAs($this->user('linh'))
            ->post('/vendors/9999/transitions', ['to_stage' => 'KYC_DOCS_RECEIVED', 'expected_from_stage' => 'CONTRACT_SIGNED'])
            ->assertNotFound();
    }
}
