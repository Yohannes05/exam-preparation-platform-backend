<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAndResultsTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->token = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->json('token');
    }

    public function test_dashboard_returns_analytics(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'students' => ['total', 'active', 'new_this_week'],
                'content' => ['questions', 'announcements'],
                'activity' => ['questions_answered', 'accuracy', 'mock_exams_completed'],
                'popular_subjects',
                'difficult_questions',
            ]);
    }

    public function test_students_list_includes_grade(): void
    {
        $grade = Grade::where('level', 8)->first();
        Student::create([
            'telegram_id' => 987654321,
            'first_name' => 'Han',
            'last_name' => 'Solo',
            'grade_id' => $grade->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/students');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Grade 8', $response->json('data.0.grade.name'));
        $this->assertArrayHasKey('accuracy', $response->json('data.0'));
    }

    public function test_student_detail_returns_progress(): void
    {
        $student = Student::create(['telegram_id' => 111, 'first_name' => 'Ada']);

        $this->withToken($this->token)
            ->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonStructure(['student', 'progress' => ['questions_answered', 'accuracy', 'by_subject'], 'mistakes', 'attempts']);
    }

    public function test_results_listing_and_detail(): void
    {
        $grade = Grade::where('level', 8)->first();
        $exam = Exam::where('type', 'chapter_test')->first();
        $student = Student::create(['telegram_id' => 222, 'first_name' => 'Bo', 'grade_id' => $grade->id]);

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'total_questions' => 3,
            'correct_answers' => 2,
            'score' => 67,
            'passed' => true,
            'answers' => [],
            'question_ids' => [1, 2, 3],
        ]);

        $list = $this->withToken($this->token)->getJson('/api/results');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertEquals($attempt->id, $list->json('data.0.id'));

        $detail = $this->withToken($this->token)->getJson("/api/results/{$attempt->id}");
        $detail->assertOk()->assertJsonStructure(['attempt', 'breakdown']);
    }

    public function test_announcements_are_filterable(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/announcements');
        $response->assertOk();
    }
}
