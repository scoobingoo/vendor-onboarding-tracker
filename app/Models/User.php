<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An Ops Coordinator. Accounts are seeded; there is no sign-up.
 *
 * @property int $id
 * @property string $username
 * @property string $display_name
 */
#[Fillable(['username', 'display_name', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** @return HasMany<Vendor, $this> */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'coordinator_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
