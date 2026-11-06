<?php

namespace App\Models;

use App\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * `stage` and `stage_entered_at` are a cache of the vendor's latest stage
 * transition. They keep the list query simple and give the compare-and-set
 * in ChangeVendorStage something to compare against. The history in
 * stage_transitions is the source of truth.
 *
 * @property int $id
 * @property string $name
 * @property string $region
 * @property int|null $coordinator_id
 * @property Stage $stage
 * @property CarbonImmutable $stage_entered_at
 */
#[Fillable(['name', 'region', 'coordinator_id', 'stage', 'stage_entered_at'])]
class Vendor extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    /**
     * The full stage history, newest first.
     *
     * @return HasMany<StageTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(StageTransition::class)
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }

    /** @return HasOne<StageTransition, $this> */
    public function latestTransition(): HasOne
    {
        return $this->hasOne(StageTransition::class)->latestOfMany(['changed_at', 'id']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'stage_entered_at' => 'immutable_datetime',
        ];
    }
}
