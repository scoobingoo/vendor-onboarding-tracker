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

    public function test_derives_time_in_stage_and_stuck_status_from_history(): void
    {
        $vendors = $this->listAs('linh');

        $c = $vendors['Company C']; // Contract Signed 9 days ago
        $this->assertSame([Stage::ContractSigned, 9, true], [$c->stage, $c->daysInStage, $c->isStuck]);

        $d = $vendors['Company D']; // KYC Verified 3 days ago
        $this->assertSame([Stage::KycVerified, 3, false], [$d->stage, $d->daysInStage, $d->isStuck]);

        $a = $vendors['Company A']; // Active 24 days ago
        $this->assertSame([Stage::Active, false], [$a->stage, $a->isStuck]);
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
            ['Company E', 'Company B', 'Company C', 'Company G', 'Company D', 'Company H', 'Company F', 'Company A'],
            $response->viewData('vendors')->pluck('name')->all(),
        );
        $response->assertSee('<tr class="stuck"', false);
        $response->assertSee('Stuck only (4)');
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
