<?php

namespace App\Models;

use App\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One stage change: who, when, from, to, and why. The log is append-only: a
 * mistake is fixed by adding a new (backward) transition, never by editing or
 * deleting the past. This model refuses updates and deletes, and database
 * triggers enforce the same rule for raw SQL.
 *
 * @property int $id
 * @property int $vendor_id
 * @property Stage|null $from_stage null for the vendor's first entry (created by Sales)
 * @property Stage $to_stage
 * @property int|null $changed_by null when the change came from outside the app (Sales / imported)
 * @property CarbonImmutable $changed_at
 * @property string|null $note
 */
#[Fillable(['vendor_id', 'from_stage', 'to_stage', 'changed_by', 'changed_at', 'note'])]
class StageTransition extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('stage_transitions is append-only'));
        static::deleting(fn () => throw new LogicException('stage_transitions is append-only'));
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function isBackward(): bool
    {
        return $this->from_stage !== null && $this->to_stage->index() < $this->from_stage->index();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_stage' => Stage::class,
            'to_stage' => Stage::class,
            'changed_at' => 'immutable_datetime',
        ];
    }
}
