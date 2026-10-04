<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionAttempt extends Model
{
    public $timestamps = ['created_at', 'updated_at'];

    protected $fillable = [
        'student_id', 'question_id', 'exam_attempt_id', 'context',
        'chosen_option_id', 'is_correct', 'answered_at',
    ];

    protected $casts = ['is_correct' => 'boolean', 'answered_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
