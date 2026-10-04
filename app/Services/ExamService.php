<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Chapter tests (spec §8) and mock examinations (spec §9):
 * builds papers, tracks time limits, scores and finalizes attempts.
 */
class ExamService
{
    public function __construct(private readonly ProgressService $progress)
    {
    }

    /** Questions eligible for an exam, respecting chapter/difficulty config. */
    public function eligibleQuestions(Exam $exam): Collection
    {
        return Question::where('is_active', true)
            ->where('grade_id', $exam->grade_id)
            ->where('subject_id', $exam->subject_id)
            ->when($exam->chapter_id, fn ($q) => $q->where('chapter_id', $exam->chapter_id))
            ->with('options')
            ->get();
    }

    /** Build the ordered paper: pinned questions, else draw per config. */
    public function buildPaper(Exam $exam): Collection
    {
        $pinned = $exam->pinnedQuestions()->with('options')->get();

        if ($pinned->isNotEmpty()) {
            return $pinned->take($exam->question_count)->values();
        }

        $pool = $this->eligibleQuestions($exam);
        $distribution = $exam->distribution ?? [];

        // Difficulty distribution: {"easy": 3, "medium": 5, "hard": 2}
        if (! empty($distribution['difficulties'])) {
            $selected = collect();
            foreach ($distribution['difficulties'] as $difficulty => $count) {
                $selected = $selected->concat(
                    $pool->where('difficulty', $difficulty)->shuffle()->take((int) $count)
                );
            }

            $remaining = $exam->question_count - $selected->count();
            if ($remaining > 0) {
                $selected = $selected->concat(
                    $pool->reject(fn ($q) => $selected->contains('id', $q->id))->shuffle()->take($remaining)
                );
            }

            return $selected->take($exam->question_count)->values();
        }

        return $pool->shuffle()->take($exam->question_count)->values();
    }

    /** Start (or resume) an attempt for a student. */
    public function startAttempt(Student $student, Exam $exam): ExamAttempt
    {
        $attempt = ExamAttempt::where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->where('status', 'in_progress')
            ->first();

        if ($attempt && $attempt->expires_at && $attempt->expires_at->isPast()) {
            $this->finalize($attempt);
            $attempt = null;
        }

        if ($attempt) {
            return $attempt;
        }

        $paper = $this->buildPaper($exam);

        return ExamAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => 'in_progress',
            'started_at' => now(),
            'expires_at' => now()->addMinutes($exam->time_limit_minutes),
            'total_questions' => $paper->count(),
            'question_ids' => $paper->pluck('id')->all(),
            'answers' => [],
        ]);
    }

    /** Remaining seconds on the clock (0 when finished/expired). */
    public function secondsLeft(ExamAttempt $attempt): int
    {
        if ($attempt->status === 'completed' || ! $attempt->expires_at) {
            return 0;
        }

        return max(0, now()->diffInSeconds($attempt->expires_at, false));
    }

    /**
     * Answer one question of an attempt. Returns
     * ['correct' => bool, 'correct_option' => QuestionOption, 'finished' => bool].
     */
    public function answer(ExamAttempt $attempt, int $questionId, int $optionId): array
    {
        $attempt->loadMissing('exam');
        $question = Question::with('options')->findOrFail($questionId);
        $option = $question->options->firstWhere('id', $optionId);

        abort_if(! $option, 422, 'Invalid option for this question.');
        abort_if(! in_array($questionId, array_map('intval', $attempt->question_ids ?? [])), 422, 'Question is not part of this attempt.');

        $correct = (bool) $option->is_correct;

        $answers = $attempt->answers ?? [];
        $answers[$questionId] = ['option' => $optionId, 'correct' => $correct];
        $attempt->answers = $answers;
        $attempt->save();

        $this->progress->recordAnswer(
            $attempt->student,
            $question,
            $optionId,
            $correct,
            $attempt->exam->type === 'mock' ? 'mock' : 'test',
            $attempt,
        );

        $finished = count($answers) >= $attempt->total_questions
            || $this->secondsLeft($attempt) === 0;

        if ($finished) {
            $this->finalize($attempt);
        }

        return [
            'correct' => $correct,
            'correct_option' => $question->correctOption(),
            'finished' => $finished,
        ];
    }

    /** Score and close an attempt (auto-called on last answer or timeout). */
    public function finalize(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status === 'completed') {
            return $attempt;
        }

        $attempt->loadMissing('exam');
        $answers = $attempt->answers ?? [];
        $total = max(1, $attempt->total_questions);
        $correct = collect($answers)->where('correct', true)->count();
        $score = (int) round($correct / $total * 100);

        $attempt->update([
            'status' => 'completed',
            'completed_at' => now(),
            'correct_answers' => $correct,
            'score' => $score,
            'passed' => $score >= $attempt->exam->pass_mark,
            'time_spent_seconds' => $attempt->started_at
                ? max(0, $attempt->started_at->diffInSeconds(now()))
                : 0,
        ]);

        return $attempt->refresh();
    }
}
