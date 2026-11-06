<?php

namespace Tests\Feature;

use App\Domain\Stage;
use App\Domain\VendorSummary;
use Illuminate\Support\Collection;

class TrackerPageTest extends DemoDataTestCase
{
    /** @return Collection<string, VendorSummary> */
    private function listAs(string $username, string $query = ''): Collection
    {
        return $this->actingAs($this->user($username))
            ->get('/'.$query)
            ->assertOk()
            ->viewData('vendors')
            ->keyBy('name');
    }

    public function test_derives_the_sheets_last_updated_column_and_stuck_status_from_history(): void
    {
        // The example spreadsheet from the brief, read on 10 Aug 2026. Nobody types
        // these dates: they come from each vendor's last stage change.
        $sheet = [
            //              stage                   "Last Updated"  days  stuck
            'Company A' => [Stage::Active,          '2026-07-02',   39,   false], // Active is never stuck
            'Company B' => [Stage::KycDocsReceived, '2026-07-18',   23,   true],
            'Company C' => [Stage::ContractSigned,  '2026-07-28',   13,   true],
            'Company D' => [Stage::KycVerified,     '2026-08-01',   9,    true],
            'Company E' => [Stage::KycDocsReceived, '2026-07-15',   26,   true],
            'Company F' => [Stage::ContractSent,    '2026-08-05',   5,    false],
        ];

        $vendors = $this->listAs('linh');

        foreach ($sheet as $name => $expected) {
            $v = $vendors[$name];
            $this->assertSame(
                $expected,
                [$v->stage, $v->stageEnteredAt->toDateString(), $v->daysInStage, $v->isStuck],
                $name,
            );
        }
    }

    public function test_updates_stuck_status_as_time_passes_with_no_manual_last_updated_field(): void
    {
        $this->assertFalse($this->listAs('linh')['Company H']->isStuck); // 2 days in stage

        $this->travelTo($this->start->addDays(6));

        $this->assertTrue($this->listAs('linh')['Company H']->isStuck); // 8 days in stage
    }

    public function test_lists_stuck_vendors_first_and_highlights_them(): void
    {
        $response = $this->actingAs($this->user('linh'))->get('/')->assertOk();

        $this->assertSame(
            ['Company E', 'Company B', 'Company C', 'Company D', 'Company G', 'Company F', 'Company H', 'Company A'],
            $response->viewData('vendors')->pluck('name')->all(),
        );
        $response->assertSee('<tr class="stuck"', false);
        $response->assertSee('Stuck only (5)');
    }

    public function test_applies_filters_from_the_query_string(): void
    {
        $this->assertSame(['Company C', 'Company H', 'Company A'], $this->listAs('linh', '?mine=1')->keys()->all());
        $this->assertSame(['Company C'], $this->listAs('linh', '?mine=1&stuck=1')->keys()->all());
        $this->assertSame(['Company E', 'Company B'], $this->listAs('linh', '?stage=KYC_DOCS_RECEIVED')->keys()->all());
        $this->assertSame(['Company C', 'Company H'], $this->listAs('linh', '?q=hanoi')->keys()->all());
    }

    public function test_shows_a_vendors_full_history_newest_first(): void
    {
        $response = $this->actingAs($this->user('huy'))
            ->get(route('vendors.show', $this->vendor('Company B')))
            ->assertOk();

        $history = $response->viewData('history');
        $this->assertCount(5, $history);
        $this->assertSame(Stage::KycDocsReceived, $history[0]->to_stage);
        $this->assertTrue($history[0]->isBackward());
        $this->assertNull($history[4]->from_stage);

        $response->assertSee('Marked verified by mistake');
        $response->assertSee('added vendor at');
    }

    public function test_returns_404_for_an_unknown_vendor(): void
    {
        $this->actingAs($this->user('linh'))->get('/vendors/9999')->assertNotFound();
    }
}
