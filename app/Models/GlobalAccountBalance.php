<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalAccountBalance extends Model
{
    protected $fillable = ['user_id', 'currency', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
