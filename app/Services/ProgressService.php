<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\Mistake;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\Score;
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

    /** Award one point per correct answer to the weekly and lifetime totals. */
    public function awardQuizPoints(Student $student, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        $weekId = (int) now('Africa/Addis_Ababa')->format('oW');
        $score = Score::firstOrCreate(
            ['student_id' => $student->id, 'week_id' => $weekId],
            ['weekly_points' => 0, 'total_points' => 0]
        );
        $score->increment('weekly_points', $points);
        $score->increment('total_points', $points);
    }

    public function weeklyScore(Student $student): int
    {
        return (int) Score::where('student_id', $student->id)
            ->where('week_id', (int) now('Africa/Addis_Ababa')->format('oW'))
            ->sum('weekly_points');
    }

    public function allTimeScore(Student $student): int
    {
        return (int) Score::where('student_id', $student->id)->sum('total_points');
    }

    /** Rank of $student among students in their grade by weekly points. */
    public function weeklyRank(Student $student): ?int
    {
        return $this->rankOf($student, weekly: true);
    }

    /** Rank of $student among students in their grade by lifetime points. */
    public function allTimeRank(Student $student): ?int
    {
        return $this->rankOf($student, weekly: false);
    }

    /** Competition rank: one plus the number of classmates with higher points. */
    protected function rankOf(Student $student, bool $weekly): ?int
    {
        if (! $student->grade_id) {
            return null;
        }

        $pointsColumn = $weekly ? 'weekly_points' : 'total_points';
        $scores = Score::query()
            ->join('students', 'students.id', '=', 'scores.student_id')
            ->where('students.grade_id', $student->grade_id)
            ->when($weekly, fn ($q) => $q->where('scores.week_id', (int) now('Africa/Addis_Ababa')->format('oW')))
            ->selectRaw('scores.student_id, SUM(scores.'.$pointsColumn.') as points')
            ->groupBy('scores.student_id')
            ->get()
            ->keyBy('student_id');

        $studentPoints = (int) ($scores->get($student->id)->points ?? 0);
        if ($studentPoints === 0) {
            return null;
        }

        return 1 + $scores->filter(fn ($row) => (int) $row->points > $studentPoints)->count();
    }
}
