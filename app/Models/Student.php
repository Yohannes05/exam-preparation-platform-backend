<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'telegram_id', 'telegram_username', 'first_name', 'last_name', 'grade_id',
        'is_active', 'settings', 'last_seen_at', 'joined_at', 'activated_until',
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'is_active' => 'boolean',
        'settings' => 'array',
        'last_seen_at' => 'datetime',
        'joined_at' => 'datetime',
        'activated_until' => 'datetime',
    ];

    protected $appends = ['display_name'];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function questionAttempts(): HasMany
    {
        return $this->hasMany(QuestionAttempt::class);
    }

    public function mistakes(): HasMany
    {
        return $this->hasMany(Mistake::class);
    }

    public function getDisplayNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->telegram_username ?: 'Student #'.$this->id);
    }
}
