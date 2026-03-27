<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluggyItem extends Model
{
    protected $fillable = [
        'user_id',
        'pluggy_item_id',
        'connector_name',
        'connector_logo',
        'status',
        'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
