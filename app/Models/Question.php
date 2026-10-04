<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = [
        'grade_id', 'subject_id', 'chapter_id', 'topic_id', 'question_text',
        'question_type', 'difficulty', 'explanation', 'source', 'year', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'year' => 'integer'];

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

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('label');
    }

    public function correctOption()
    {
        return $this->options()->where('is_correct', true)->first();
    }
}
