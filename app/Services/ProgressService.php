<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\Mistake;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\Student;

/**
 * Records answers, aggregates progress (spec §10) and manages the
 * My Mistakes area (spec §11).
 */
class ProgressService
{
    /** Persist one answered question and update the mistake book. */
    public function recordAnswer(
        Student $student,
        Question $question,
        ?int $chosenOptionId,
        bool $correct,
        string $context = 'practice',
        ?ExamAttempt $attempt = null,
    ): QuestionAttempt {
        $attemptRow = QuestionAttempt::create([
            'student_id' => $student->id,
            'question_id' => $question->id,
            'exam_attempt_id' => $attempt?->id,
            'context' => $context,
            'chosen_option_id' => $chosenOptionId,
            'is_correct' => $correct,
            'answered_at' => now(),
        ]);

        $this->updateMistakeBook($student, $question, $correct, $context);

        $student->forceFill(['last_seen_at' => now()])->save();

        return $attemptRow;
    }

    protected function updateMistakeBook(Student $student, Question $question, bool $correct, string $context): void
    {
        $mistake = Mistake::firstOrNew([
            'student_id' => $student->id,
            'question_id' => $question->id,
        ]);

        if ($correct) {
            if ($mistake->exists) {
                $mistake->right_count++;
                // Answered correctly while reviewing → mastered.
                if ($context === 'mistake') {
                    $mistake->is_mastered = true;
                }
                $mistake->save();
            }

            return;
        }

        $mistake->wrong_count = ($mistake->wrong_count ?? 0) + ($mistake->exists ? 1 : 0);
        if (! $mistake->exists) {
            $mistake->wrong_count = 1;
        }
        $mistake->is_mastered = false;
        $mistake->last_wrong_at = now();
        $mistake->save();
    }

    /** Full progress snapshot for a student (spec §10). */
    public function forStudent(Student $student): array
    {
        $attempts = QuestionAttempt::where('student_id', $student->id);

        $answered = (clone $attempts)->count();
        $correct = (clone $attempts)->where('is_correct', true)->count();

        $testsCompleted = ExamAttempt::where('student_id', $student->id)
            ->where('status', 'completed')
            ->whereHas('exam', fn ($q) => $q->where('type', 'chapter_test'))
            ->count();

        $mocksCompleted = ExamAttempt::where('student_id', $student->id)
            ->where('status', 'completed')
            ->whereHas('exam', fn ($q) => $q->where('type', 'mock'))
            ->count();

        return [
            'questions_answered' => $answered,
            'correct' => $correct,
            'wrong' => $answered - $correct,
            'accuracy' => $answered > 0 ? round($correct / $answered * 100, 1) : 0,
            'tests_completed' => $testsCompleted,
            'mock_exams_completed' => $mocksCompleted,
            'open_mistakes' => Mistake::where('student_id', $student->id)
                ->where('is_mastered', false)->count(),
            'by_subject' => $this->aggregateBy($student, 'subjects', 'subject_id'),
            'by_chapter' => $this->aggregateBy($student, 'chapters', 'chapter_id'),
            'weak_topics' => $this->weakTopics($student),
        ];
    }

    protected function aggregateBy(Student $student, string $table, string $column): array
    {
        // subjects use `name`; chapters use `title`.
        $label = $table === 'subjects' ? 'name' : 'title';

        return QuestionAttempt::query()
            ->where('student_id', $student->id)
            ->join('questions', 'questions.id', '=', 'question_attempts.question_id')
            ->join($table, $table.'.id', '=', "questions.$column")
            ->selectRaw("$table.id as id, $table.$label as name, count(*) as answered,
                sum(case when question_attempts.is_correct then 1 else 0 end) as correct")
            ->groupBy("$table.id", "$table.$label")
            ->orderByDesc('answered')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'answered' => (int) $row->answered,
                'correct' => (int) $row->correct,
                'accuracy' => round($row->correct / max(1, $row->answered) * 100, 1),
            ])
            ->toArray();
    }

    /** Topics with the lowest accuracy, min 2 attempts. */
    protected function weakTopics(Student $student): array
    {
        return QuestionAttempt::query()
            ->where('student_id', $student->id)
            ->join('questions', 'questions.id', '=', 'question_attempts.question_id')
            ->join('topics', 'topics.id', '=', 'questions.topic_id')
            ->selectRaw('topics.id, topics.title, count(*) as answered,
                sum(case when question_attempts.is_correct then 1 else 0 end) as correct')
            ->groupBy('topics.id', 'topics.title')
            ->havingRaw('count(*) >= 2')
            ->orderByRaw('correct * 1.0 / count(*)')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->title,
                'answered' => (int) $row->answered,
                'accuracy' => round($row->correct / $row->answered * 100, 1),
            ])
            ->toArray();
    }

    /** Unmastered mistakes with full question payload for review practice. */
    public function mistakes(Student $student, int $limit = 20)
    {
        return Mistake::where('student_id', $student->id)
            ->where('is_mastered', false)
            ->with(['question.options', 'question.chapter', 'question.subject'])
            ->orderByDesc('last_wrong_at')
            ->limit($limit)
            ->get();
    }

    /** Rank of $student among all students by weekly score (1-based, or null). */
    public function weeklyRank(Student $student): ?int
    {
        return $this->rankOf(
            $student,
            closed: false,
            where: fn ($q) => $q
                ->where('exam_attempts.status', 'completed')
                ->where('exam_attempts.started_at', '>=', now()->subWeek()),
        );
    }

    /** Rank of $student among all students by all-time score (1-based, or null). */
    public function allTimeRank(Student $student): ?int
    {
        return $this->rankOf($student, closed: false, where: fn ($q) => $q);
    }

    /** 1-based rank of $student among students with completed exams in scope.
     *  Competition ranking: students with the same score share a rank.
     *  Returns null when $student has no completed attempts in scope.
     */
    protected function rankOf(Student $student, bool $closed, callable $where): ?int
    {
        $ranked = Student::query()
            ->join('exam_attempts', 'exam_attempts.student_id', '=', 'students.id')
            ->where('exam_attempts.status', 'completed')
            ->when(!$closed, fn ($q) => $q->where('exam_attempts.started_at', '>=', now()->subWeek()))
            ->select('students.id', 'exam_attempts.score')
            ->pluck('score', 'id');

        $studentScore = $ranked->get($student->id);

        if ($studentScore === null) {
            return null;
        }

        // Competition ranking: students with a strictly higher score rank above;
        // ties share the same rank.
        $rank = 1;
        foreach ($ranked as $id => $score) {
            if ($id === $student->id) {
                break;
            }
            if ($score > $studentScore) {
                $rank++;
            }
        }

        return $rank;
    }
}
