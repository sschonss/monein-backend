<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'auth', 'p256dh'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
