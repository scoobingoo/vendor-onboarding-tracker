<?php

namespace Tests\Unit;

use App\Domain\Stage;
use App\Domain\VendorFilters;
use App\Domain\VendorList;
use App\Domain\VendorSummary;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The list order is how coordinators decide what to work on next, so check
 * that stuck vendors always come first.
 */
class VendorListTest extends TestCase
{
    /** @var list<VendorSummary> */
    private array $vendors;

    protected function setUp(): void
    {
        $this->vendors = [
            $this->vendor(1, Stage::Active, 30, false),
            $this->vendor(2, Stage::ContractSent, 2, false),
            $this->vendor(3, Stage::KycDocsReceived, 21, true, coordinatorId: 2),
            $this->vendor(4, Stage::ContractSigned, 9, true),
            $this->vendor(5, Stage::KycVerified, 5, false, coordinatorId: null),
        ];
    }

    private function vendor(int $id, Stage $stage, int $daysInStage, bool $isStuck, ?int $coordinatorId = 1): VendorSummary
    {
        return new VendorSummary(
            id: $id,
            name: "Vendor {$id}",
            region: 'HCMC',
            stage: $stage,
            stageEnteredAt: CarbonImmutable::parse('2026-08-01T00:00:00Z'),
            daysInStage: $daysInStage,
            isStuck: $isStuck,
            coordinatorId: $coordinatorId,
        );
    }

    /** @return list<int> */
    private function ids(VendorFilters $filters = new VendorFilters): array
    {
        return VendorList::filterAndSort($this->vendors, $filters, currentUserId: 1)->pluck('id')->all();
    }

    public function test_puts_stuck_vendors_first_longest_waiting_on_top_and_finished_vendors_last(): void
    {
        $this->assertSame([3, 4, 5, 2, 1], $this->ids());
    }

    public function test_combines_filters(): void
    {
        $this->assertSame([3, 4], $this->ids(new VendorFilters(stuckOnly: true)));
        $this->assertSame([4, 2, 1], $this->ids(new VendorFilters(mineOnly: true)));
        $this->assertSame([4], $this->ids(new VendorFilters(stuckOnly: true, mineOnly: true)));
        $this->assertSame([5], $this->ids(new VendorFilters(stage: Stage::KycVerified)));
        $this->assertSame([2], $this->ids(new VendorFilters(search: 'vendor 2')));
    }

    public function test_reads_filters_from_the_query_string_and_ignores_junk(): void
    {
        $filters = VendorFilters::fromQuery(['stage' => 'KYC_VERIFIED', 'stuck' => '1', 'q' => '  hanoi ']);
        $this->assertEquals(new VendorFilters(stage: Stage::KycVerified, stuckOnly: true, search: 'hanoi'), $filters);
        $this->assertSame(['stage' => 'KYC_VERIFIED', 'stuck' => '1', 'q' => 'hanoi'], $filters->toQuery());

        $this->assertEquals(new VendorFilters, VendorFilters::fromQuery(['stage' => 'Done', 'q' => ['x']]));
    }
}
