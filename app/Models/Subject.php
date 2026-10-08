<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['grade_id', 'name', 'language', 'description', 'order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'order' => 'integer'];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
