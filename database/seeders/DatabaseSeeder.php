<?php

namespace Database\Seeders;

use App\Domain\Stage;
use App\Models\StageTransition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data. Timestamps are relative to "now", so the demo always shows a mix
 * of stuck and healthy vendors. Each vendor gets a full history, as if the
 * spreadsheet had been migrated with its past changes.
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
                [Stage::ContractSent, 40, null],
                [Stage::ContractSigned, 35, 'linh'],
                [Stage::KycDocsReceived, 30, 'linh'],
                [Stage::KycVerified, 26, 'linh', 'Approved by Compliance on Slack.'],
                [Stage::Active, 24, 'linh'],
            ],
        ],
        [
            // Shows how the audit trail explains data that "looks wrong".
            'name' => 'Company B',
            'region' => 'Can Tho',
            'coordinator' => 'huy',
            'history' => [
                [Stage::ContractSent, 30, null],
                [Stage::ContractSigned, 26, 'huy'],
                [Stage::KycDocsReceived, 22, 'huy'],
                [Stage::KycVerified, 19, 'mai'],
                [Stage::KycDocsReceived, 18, 'huy', 'Marked verified by mistake: Compliance rejected the business license (expired). Waiting on re-upload.'],
            ],
        ],
        [
            'name' => 'Company C',
            'region' => 'Hanoi',
            'coordinator' => 'linh',
            'history' => [
                [Stage::ContractSent, 12, null],
                [Stage::ContractSigned, 9, 'linh'],
            ],
        ],
        [
            'name' => 'Company D',
            'region' => 'HCMC',
            'coordinator' => 'mai',
            'history' => [
                [Stage::ContractSent, 20, null],
                [Stage::ContractSigned, 15, 'mai'],
                [Stage::KycDocsReceived, 10, 'mai'],
                [Stage::KycVerified, 3, 'mai'],
            ],
        ],
        [
            'name' => 'Company E',
            'region' => 'Da Nang',
            'coordinator' => 'huy',
            'history' => [
                [Stage::ContractSent, 28, null],
                [Stage::ContractSigned, 25, 'huy'],
                [Stage::KycDocsReceived, 21, 'huy'],
            ],
        ],
        [
            'name' => 'Company F',
            'region' => 'HCMC',
            'coordinator' => 'mai',
            'history' => [
                [Stage::ContractSent, 1, null],
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
