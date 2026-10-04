<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndCrudTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->token = $this->login();
    }

    protected function login(): string
    {
        return $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/grades')->assertStatus(401);
        $this->getJson('/api/dashboard')->assertStatus(401);
        $this->getJson('/api/students')->assertStatus(401);
    }

    public function test_me_returns_the_admin(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', 'admin@example.com');
    }

    public function test_grade_crud_round_trip(): void
    {
        // List seeded grades.
        $response = $this->withToken($this->token)->getJson('/api/v1/grades');
        $response->assertOk();
        $this->assertCount(3, $response->json('data'));

        // Create.
        $created = $this->withToken($this->token)->postJson('/api/v1/grades', [
            'name' => 'Grade 9',
            'level' => 9,
            'description' => 'New grade',
            'is_active' => true,
        ]);
        $created->assertStatus(201);
        $id = $created->json('id');

        // Read.
        $this->withToken($this->token)->getJson("/api/v1/grades/$id")
            ->assertOk()
            ->assertJsonPath('name', 'Grade 9');

        // Update.
        $this->withToken($this->token)->putJson("/api/v1/grades/$id", [
            'name' => 'Grade 9 (revised)',
            'level' => 9,
        ])->assertOk()->assertJsonPath('name', 'Grade 9 (revised)');

        // Delete.
        $this->withToken($this->token)->deleteJson("/api/v1/grades/$id")
            ->assertOk();
        $this->withToken($this->token)->getJson("/api/v1/grades/$id")
            ->assertStatus(404);
    }

    public function test_validation_errors_are_reported(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/grades', ['name' => 'No level'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['level']);
    }

    public function test_duplicate_grade_level_is_rejected_as_validation_error(): void
    {
        // Level 12 is seeded; the DB also enforces a unique index on it.
        $this->withToken($this->token)
            ->postJson('/api/v1/grades', ['name' => 'Grade 12 copy', 'level' => 12])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['level']);
    }

    public function test_grade_update_does_not_collide_with_its_own_level(): void
    {
        $id = $this->withToken($this->token)->postJson('/api/v1/grades', [
            'name' => 'Grade 9',
            'level' => 9,
        ])->assertStatus(201)->json('id');

        $this->withToken($this->token)
            ->putJson("/api/v1/grades/$id", ['name' => 'Grade 9 renamed', 'level' => 9])
            ->assertOk()
            ->assertJsonPath('name', 'Grade 9 renamed');
    }

    public function test_subject_without_order_gets_the_default(): void
    {
        $grade = \App\Models\Grade::where('level', 8)->first();

        // The form sends null when 'Sort order' is left empty; the column is
        // NOT NULL default 0, so the API must coerce instead of crashing.
        $created = $this->withToken($this->token)
            ->postJson('/api/v1/subjects', [
                'grade_id' => $grade->id,
                'name' => 'Orderless Subject',
                'order' => null,
            ])
            ->assertStatus(201)
            ->assertJsonPath('order', 0);

        $this->withToken($this->token)
            ->deleteJson('/api/v1/subjects/'.$created->json('id'))
            ->assertOk();
    }

    public function test_duplicate_subject_name_within_a_grade_is_rejected(): void
    {
        $grade = \App\Models\Grade::where('level', 8)->first();
        $subject = $grade->subjects()->first();

        $this->withToken($this->token)
            ->postJson('/api/v1/subjects', [
                'grade_id' => $grade->id,
                'name' => $subject->name,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grade_id']);
    }

    public function test_same_subject_name_in_another_grade_is_allowed(): void
    {
        $grade8 = \App\Models\Grade::where('level', 8)->first();
        $grade12 = \App\Models\Grade::where('level', 12)->first();

        $this->withToken($this->token)
            ->postJson('/api/v1/subjects', ['grade_id' => $grade8->id, 'name' => 'Distinct Subject X'])
            ->assertStatus(201);

        // Same name under a different grade must not trip the composite unique.
        $this->withToken($this->token)
            ->postJson('/api/v1/subjects', ['grade_id' => $grade12->id, 'name' => 'Distinct Subject X'])
            ->assertStatus(201);
    }

    public function test_question_store_saves_nested_options(): void
    {
        $grade = \App\Models\Grade::where('level', 8)->first();
        $subject = $grade->subjects()->where('name', 'Mathematics')->first();
        $chapter = $subject->chapters()->first();

        $response = $this->withToken($this->token)->postJson('/api/v1/questions', [
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'chapter_id' => $chapter->id,
            'question_text' => 'What is 2 + 2?',
            'question_type' => 'multiple_choice',
            'difficulty' => 'easy',
            'explanation' => 'Basic addition.',
            'options' => [
                ['label' => 'A', 'text' => '3', 'is_correct' => false],
                ['label' => 'B', 'text' => '4', 'is_correct' => true],
                ['label' => 'C', 'text' => '5', 'is_correct' => false],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('options'));
        $this->assertTrue(
            collect($response->json('options'))->firstWhere('label', 'B')['is_correct']
        );
    }

    public function test_question_requires_exactly_one_correct_option(): void
    {
        $grade = \App\Models\Grade::where('level', 8)->first();
        $subject = $grade->subjects()->where('name', 'Mathematics')->first();
        $chapter = $subject->chapters()->first();

        $payload = [
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'chapter_id' => $chapter->id,
            'question_text' => 'Broken question?',
            'question_type' => 'multiple_choice',
            'difficulty' => 'easy',
            'options' => [
                ['label' => 'A', 'text' => 'yes', 'is_correct' => true],
                ['label' => 'B', 'text' => 'no', 'is_correct' => true],
            ],
        ];

        $this->withToken($this->token)
            ->postJson('/api/v1/questions', $payload)
            ->assertStatus(422);
    }

    public function test_search_and_filters_work(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/subjects?search=Math&per_page=50');
        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        foreach ($response->json('data') as $subject) {
            $this->assertStringContainsString('math', strtolower($subject['name']));
        }
    }

    public function test_announcement_publish_sets_published_at(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/announcements', [
            'title' => 'Exam week!',
            'body' => 'Mock exams run all week.',
            'audience' => 'all',
            'is_published' => true,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('published_at'));
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->withToken($this->token)->postJson('/api/logout')->assertOk();

        // Sanctum's RequestGuard caches the user on the shared app instance;
        // production boots a fresh app per request, so flush guards here.
        $this->app->make('auth')->forgetGuards();

        $this->withToken($this->token)->getJson('/api/me')->assertStatus(401);
    }
}
