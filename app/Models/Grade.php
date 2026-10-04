<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    protected $fillable = ['name', 'level', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'level' => 'integer'];

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
