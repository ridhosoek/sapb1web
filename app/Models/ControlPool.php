<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['external_id', 'code', 'name', 'is_active', 'synced_at'])]
class ControlPool extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(ControlVehicle::class, 'pool_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(ControlDepartment::class, 'pool_id');
    }
}
