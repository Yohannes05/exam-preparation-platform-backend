<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    protected $fillable = ['student_id', 'week_id', 'weekly_points', 'total_points'];

    protected $casts = [
        'student_id' => 'integer',
        'week_id' => 'integer',
        'weekly_points' => 'integer',
        'total_points' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
