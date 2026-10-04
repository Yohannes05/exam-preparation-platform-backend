<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mistake extends Model
{
    protected $fillable = [
        'student_id', 'question_id', 'wrong_count', 'right_count', 'is_mastered', 'last_wrong_at',
    ];

    protected $casts = [
        'is_mastered' => 'boolean',
        'wrong_count' => 'integer',
        'right_count' => 'integer',
        'last_wrong_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
