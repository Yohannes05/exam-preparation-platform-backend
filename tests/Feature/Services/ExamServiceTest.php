<?php

namespace Tests\Feature\Services;

use App\Models\Chapter;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Question;
use App\Models\Student;
use App\Models\Subject;
use App\Services\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Subject $subject;
    protected Chapter $chapter;
    protected Exam $exam;
    protected Student $student;
    protected ExamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $grade = Grade::where('level', 8)->first();
        $this->subject = $grade->subjects()->where('name', 'Mathematics')->first();
        $this->chapter = Chapter::where('subject_id', $this->subject->id)->where('title', 'Linear Equations')->first();
        $this->exam = Exam::where('type', 'chapter_test')->first();
        $this->student = Student::create(['telegram_id' => 555, 'first_name' => 'Test', 'grade_id' => $grade->id]);
        $this->service = app(ExamService::class);
    }

    public function test_build_paper_respects_question_count(): void
    {
        $chapterQuestions = Question::where('chapter_id', $this->chapter->id)->count();
        $this->assertGreaterThanOrEqual(3, $chapterQuestions);

        $this->exam->update(['question_count' => 2]);
        $paper = $this->service->buildPaper($this->exam->fresh());

        $this->assertCount(2, $paper);
        foreach ($paper as $question) {
            $this->assertEquals($this->chapter->id, $question->chapter_id);
        }
    }

    public function test_pinned_questions_take_priority(): void
    {
        $questions = Question::where('chapter_id', $this->chapter->id)->get();
        $this->exam->pinnedQuestions()->sync([$questions->last()->id]);

        $paper = $this->service->buildPaper($this->exam->fresh());

        $this->assertEquals($questions->last()->id, $paper->first()->id);
    }

    public function test_start_attempt_resumes_in_progress_attempt(): void
    {
        $first = $this->service->startAttempt($this->student, $this->exam);
        $second = $this->service->startAttempt($this->student, $this->exam);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals('in_progress', $first->status);
        $this->assertEquals(3, $first->total_questions);
        $this->assertNotNull($first->expires_at);
    }

    public function test_expired_attempt_is_finalized_and_new_one_started(): void
    {
        $first = $this->service->startAttempt($this->student, $this->exam);
        $first->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->service->startAttempt($this->student, $this->exam);

        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals('completed', $first->fresh()->status);
    }

    public function test_answering_all_questions_scores_and_passes(): void
    {
        $attempt = $this->service->startAttempt($this->student, $this->exam);

        foreach ($attempt->question_ids as $i => $qid) {
            $question = Question::with('options')->find($qid);
            // Answer correctly except the first question.
            $option = $i === 0
                ? $question->options->firstWhere('is_correct', false)
                : $question->options->firstWhere('is_correct', true);

            $result = $this->service->answer($attempt, $question->id, $option->id);

            if ($i < count($attempt->question_ids) - 1) {
                $this->assertFalse($result['finished']);
            } else {
                $this->assertTrue($result['finished']);
            }
        }

        $attempt->refresh();
        $this->assertEquals('completed', $attempt->status);
        $this->assertEquals(2, $attempt->correct_answers);
        $this->assertEquals(67, $attempt->score);
        $this->assertTrue($attempt->passed); // pass mark 60%
        $this->assertEquals(3, $this->student->questionAttempts()->count());
        $this->assertEquals(1, $this->student->mistakes()->count());
    }

    public function test_answering_foreign_question_is_rejected(): void
    {
        $attempt = $this->service->startAttempt($this->student, $this->exam);
        $foreign = Question::whereNotIn('id', $attempt->question_ids)->first();
        $option = $foreign->options()->first();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->answer($attempt, $foreign->id, $option->id);
    }

    public function test_seconds_left_counts_down(): void
    {
        $attempt = $this->service->startAttempt($this->student, $this->exam);
        $left = $this->service->secondsLeft($attempt);

        $this->assertGreaterThan(9 * 60, $left);
        $this->assertLessThanOrEqual(10 * 60, $left);
    }
}
