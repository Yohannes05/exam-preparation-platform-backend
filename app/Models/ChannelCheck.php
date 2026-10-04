<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelCheck extends Model
{
    protected $fillable = [
        'telegram_id',
        'joined_confirmed_at',
    ];

    protected $casts = [
        'joined_confirmed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
