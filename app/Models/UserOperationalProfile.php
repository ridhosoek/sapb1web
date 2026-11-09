<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'pool_id', 'department_id', 'warehouse_code'])]
class UserOperationalProfile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ControlPool::class, 'pool_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ControlDepartment::class, 'department_id');
    }
}
