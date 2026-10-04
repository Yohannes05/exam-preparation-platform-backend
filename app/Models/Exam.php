<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $fillable = [
        'title', 'type', 'grade_id', 'subject_id', 'chapter_id', 'question_count',
        'time_limit_minutes', 'pass_mark', 'distribution', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'question_count' => 'integer',
        'time_limit_minutes' => 'integer',
        'pass_mark' => 'integer',
        'distribution' => 'array',
    ];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** Pinned questions (if none, questions are drawn from config at attempt time). */
    public function pinnedQuestions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'exam_questions')
            ->withPivot('position')
            ->orderBy('exam_questions.position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
