<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAttempt extends Model
{
    protected $fillable = [
        'exam_id', 'student_id', 'status', 'started_at', 'expires_at', 'completed_at',
        'total_questions', 'correct_answers', 'score', 'passed', 'answers', 'question_ids',
        'time_spent_seconds',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'answers' => 'array',
        'question_ids' => 'array',
        'total_questions' => 'integer',
        'correct_answers' => 'integer',
        'score' => 'integer',
        'passed' => 'boolean',
        'time_spent_seconds' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
