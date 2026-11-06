<?php

namespace Database\Seeders;

use App\Domain\Stage;
use App\Models\StageTransition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data. Companies A-F are the example spreadsheet from the brief: same
 * region, stage, coordinator and notes, and each one entered its current stage
 * on the sheet's "Last Updated" date, read as of 10 Aug 2026 (FIXED_NOW=
 * 2026-08-10T09:00:00Z shows those exact dates). G and H are extra: a vendor
 * nobody is assigned to, and a second healthy one.
 *
 * Timestamps are relative to "now", so the demo looks the same whenever it is
 * seeded. Each vendor gets a full history, as if the spreadsheet had been
 * migrated with its past changes.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'password';

    private const USERS = [
        'linh' => 'Linh Nguyen',
        'huy' => 'Huy Tran',
        'mai' => 'Mai Pham',
    ];

    /**
     * history: [stage entered, days ago, who moved it (null = Sales / imported), note]
     *
     * @var list<array{name: string, region: string, coordinator: string|null, history: list<array{0: Stage, 1: int, 2: string|null, 3?: string}>}>
     */
    private const VENDORS = [
        [
            'name' => 'Company A',
            'region' => 'HCMC',
            'coordinator' => 'linh',
            'history' => [
                [Stage::ContractSent, 61, null],
                [Stage::ContractSigned, 56, 'linh'],
                [Stage::KycDocsReceived, 50, 'linh'],
                [Stage::KycVerified, 43, 'linh', 'Approved by Compliance on Slack.'],
                [Stage::Active, 39, 'linh'], // sheet: 2026-07-02
            ],
        ],
        [
            // Shows how the audit trail explains data that "looks wrong".
            'name' => 'Company B',
            'region' => 'Can Tho',
            'coordinator' => 'huy',
            'history' => [
                [Stage::ContractSent, 40, null],
                [Stage::ContractSigned, 35, 'huy'],
                [Stage::KycDocsReceived, 30, 'huy'],
                [Stage::KycVerified, 25, 'mai'],
                [Stage::KycDocsReceived, 23, 'huy', 'Marked verified by mistake: Compliance rejected the business license (expired). Waiting on business license re-upload.'], // sheet: 2026-07-18
            ],
        ],
        [
            'name' => 'Company C',
            'region' => 'Hanoi',
            'coordinator' => 'linh',
            'history' => [
                [Stage::ContractSent, 20, null],
                [Stage::ContractSigned, 13, 'linh'], // sheet: 2026-07-28
            ],
        ],
        [
            'name' => 'Company D',
            'region' => 'HCMC',
            'coordinator' => 'mai',
            'history' => [
                [Stage::ContractSent, 27, null],
                [Stage::ContractSigned, 22, 'mai'],
                [Stage::KycDocsReceived, 16, 'mai'],
                [Stage::KycVerified, 9, 'mai'], // sheet: 2026-08-01
            ],
        ],
        [
            'name' => 'Company E',
            'region' => 'Da Nang',
            'coordinator' => 'huy',
            'history' => [
                [Stage::ContractSent, 40, null],
                [Stage::ContractSigned, 33, 'huy'],
                [Stage::KycDocsReceived, 26, 'huy'], // sheet: 2026-07-15
            ],
        ],
        [
            'name' => 'Company F',
            'region' => 'HCMC',
            'coordinator' => 'mai',
            'history' => [
                [Stage::ContractSent, 5, null], // sheet: 2026-08-05
            ],
        ],
        [
            // Nobody has picked this vendor up, which is easy to miss in a spreadsheet.
            'name' => 'Company G',
            'region' => 'Hai Phong',
            'coordinator' => null,
            'history' => [
                [Stage::ContractSent, 8, null, 'Signed by Sales; no coordinator assigned yet.'],
            ],
        ],
        [
            'name' => 'Company H',
            'region' => 'Hanoi',
            'coordinator' => 'linh',
            'history' => [
                [Stage::ContractSent, 6, null],
                [Stage::ContractSigned, 2, 'linh'],
            ],
        ],
    ];

    public function run(): void
    {
        if (User::query()->exists()) {
            $this->command?->warn('Demo data is already there. Run `composer reset-demo` to wipe and reseed.');

            return;
        }

        $now = now()->toImmutable();

        DB::transaction(function () use ($now) {
            $userIds = [];
            foreach (self::USERS as $username => $displayName) {
                $userIds[$username] = User::create([
                    'username' => $username,
                    'display_name' => $displayName,
                    'password' => self::PASSWORD,
                ])->id;
            }
            $idOf = fn (?string $username) => $username === null ? null : $userIds[$username];

            foreach (self::VENDORS as $vendorIdx => $seed) {
                // Offset by a few hours per vendor so timestamps don't all line up.
                $at = fn (int $daysAgo) => $now->subHours($daysAgo * 24 + 2 + $vendorIdx);

                [$lastStage, $lastDaysAgo] = $seed['history'][array_key_last($seed['history'])];
                $vendor = Vendor::create([
                    'name' => $seed['name'],
                    'region' => $seed['region'],
                    'coordinator_id' => $idOf($seed['coordinator']),
                    'stage' => $lastStage,
                    'stage_entered_at' => $at($lastDaysAgo),
                ]);

                $previous = null;
                foreach ($seed['history'] as $entry) {
                    [$stage, $daysAgo, $by] = $entry;
                    StageTransition::create([
                        'vendor_id' => $vendor->id,
                        'from_stage' => $previous,
                        'to_stage' => $stage,
                        'changed_by' => $idOf($by),
                        'changed_at' => $at($daysAgo),
                        'note' => $entry[3] ?? null,
                    ]);
                    $previous = $stage;
                }
            }
        });
    }
}
